<?php

namespace PHPLRPM;

use Exception;
use RuntimeException;

use CardinalCollections\Mutable\Map;
use TIPC\UnixSocketStreamClient;

class ProcessManager
{
    private const EXIT_SUCCESS = 0;
    private const EXIT_PPID_CHANGED = 2;

    private const SUPERVISOR_PROCESS_TAG = '[lrpm supervisor]';

    private const CONFIG_TIME_INIT = 0;
    private const CONFIG_TIME_SIGHUP = 1;
    private const CONFIG_PROCESS_MAX_BACKOFF_SECONDS = 300;

    private $shouldRun = true;
    private $signalHandlers;

    private $configurationSourceClass;
    private $configPollIntervalSeconds;
    private $configSocket;
    private $configProcessId;
    private $timeOfLastConfigPoll = self::CONFIG_TIME_INIT;
    private $configProcessStarts = 0;

    private $workersMetadata;
    private $secondsBetweenProcessStatePolls = 1;

    private $controlMessageHandler;

    public function __construct(string $configurationSourceClass, int $configPollIntervalSeconds = 30)
    {
        fwrite(STDERR, "==> lrpm starting" . PHP_EOL);
        $this->configurationSourceClass = $configurationSourceClass;
        $this->configPollIntervalSeconds = $configPollIntervalSeconds;
        $this->workersMetadata = new WorkerMetadata();
        $this->controlMessageHandler = new ControlMessageHandler($this);
        fwrite(STDERR, "==> Registering supervisor signal handlers" . PHP_EOL);
        $this->installSignalHandlers();
    }

    public function shutdown(): void
    {
        $this->shouldRun = false;
    }

    public function getStatus(): array
    {
        return $this->workersMetadata->getAll();
    }

    public function scheduleConfigReload(): void
    {
        $this->timeOfLastConfigPoll = 0;
    }

    public function scheduleRestartOnDemand($jobId): string
    {
        return $this->workersMetadata->scheduleRestartOnDemand($jobId);
    }

    private function installSignalHandlers(): void
    {
        $this->signalHandlers = [
            SIGCHLD => function (int $signo, $_siginfo) {
                fwrite(STDERR, "==> Supervisor caught SIGCHLD ($signo)" . PHP_EOL);
                $this->handleTerminatedChildProcesses();
            },
            SIGTERM => function (int $signo, $_siginfo) {
                fwrite(STDERR, "==> Supervisor caught SIGTERM ($signo), initiating lrpm shutdown" . PHP_EOL);
                $this->shutdown();
            },
            SIGINT => function (int $signo, $_siginfo) {
                fwrite(STDERR, "==> Supervisor caught SIGINT ($signo), initiating lrpm shutdown" . PHP_EOL);
                $this->shutdown();
            },
            SIGHUP => function (int $signo, $_siginfo) {
                fwrite(STDERR, "==> Supervisor caught SIGHUP ($signo), will reload configuration" . PHP_EOL);
                $this->timeOfLastConfigPoll = self::CONFIG_TIME_SIGHUP;
            }
        ];

        foreach ($this->signalHandlers as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }
    }

    private static function setSupervisorProcessTitle(): void
    {
        cli_set_process_title(
            'php ' . implode(' ', $_SERVER['argv'])
            . ' ' . self::SUPERVISOR_PROCESS_TAG
        );
    }

    private static function setChildProcessTitle($tag): void
    {
        cli_set_process_title(
            preg_replace(
                '/' . preg_quote(self::SUPERVISOR_PROCESS_TAG) . '$/',
                "[lrpm $tag]",
                cli_get_process_title()
            )
        );
    }

    private function pollConfiguration(): array
    {
        $recvBufSize = 8 * 1024 * 1024;
        $client = new UnixSocketStreamClient($this->configSocket, $recvBufSize);
        if ($client->connect() === false) {
            throw new ConfigurationPollException("Could not connect to socket {$this->configSocket}");
        }
        if ($client->sendMessage('config') === false) {
            $client->disconnect();
            throw new ConfigurationPollException("Could not send config query message over socket {$this->configSocket}");
        }
        $signals = array_keys($this->signalHandlers);
        pcntl_sigprocmask(SIG_BLOCK, $signals);
        if (($response = $client->receiveMessage()) === false ) {
            pcntl_sigprocmask(SIG_UNBLOCK, $signals);
            $client->disconnect();
            throw new ConfigurationPollException("Failed to read config response from {$this->configSocket}");
        }
        pcntl_sigprocmask(SIG_UNBLOCK, $signals);
        $client->disconnect();
        if ($response === ConfigurationService::RESP_ERROR_CONFIG_SOURCE) {
            throw new ConfigurationPollException("Config process at {$this->configSocket} responded with an error message");
        }
        return Serialization::deserialize($response);
    }

    private function startConfigurationProcess(): void
    {
        $supervisorPid = getmypid();
        $signals = array_keys($this->signalHandlers);
        flush();
        pcntl_sigprocmask(SIG_BLOCK, $signals);
        $pid = pcntl_fork();
        if ($pid === 0) { // child process
            foreach ($this->signalHandlers as $signal => $_handler) {
                pcntl_signal($signal, SIG_DFL);
            }
            $configPid = getmypid();
            fwrite(STDERR, "--> Config process with PID $configPid running" . PHP_EOL);
            self::setChildProcessTitle('config');
            $configurationService = new ConfigurationService($this->configurationSourceClass);
            $configurationService->startMessageListener();
            fwrite(STDERR, "--> Signaling parent $supervisorPid that we are ready to accept messages" . PHP_EOL);
            posix_kill($supervisorPid, SIGHUP);
            while (true) {
                $ppid = posix_getppid();
                if ($ppid != $supervisorPid) {
                    fwrite(STDERR, "--> Parent PID changed, config process exiting" . PHP_EOL);
                    exit(self::EXIT_PPID_CHANGED);
                }
                $configurationService->checkMessages();
                flush();
                time_nanosleep(0, 100 * 1000 * 1000);
            }
        } elseif ($pid > 0) { // parent process
            pcntl_sigprocmask(SIG_UNBLOCK, $signals);
            $this->configProcessId = $pid;
            fwrite(STDERR, "==> Forked config process with PID: $pid" . PHP_EOL);
            fwrite(STDERR, "==> Waiting for config service to let us know it's ready" . PHP_EOL);
            $timeout = 60;
            $remaining = sleep($timeout);
            pcntl_signal_dispatch();
            if ($remaining == 0 && $this->timeOfLastConfigPoll == self::CONFIG_TIME_INIT) {
                throw new RuntimeException("Config process did not notify readiness");
            }
            $this->configSocket = IPCUtilities::clientFindUnixSocket('config', IPCUtilities::getSocketDirs());
            if (is_null($this->configSocket)) {
                throw new RuntimeException("lrpm supervisor could not find config process Unix domain socket");
            }
        } else {
            throw new RuntimeException("Error forking configuration process: $pid");
        }
    }

    private function stopConfigurationProcess(): void
    {
        $termTimeoutSeconds = 5;
        if (is_null($this->configProcessId)) {
            return;
        }
        posix_kill($this->configProcessId, SIGTERM);
        sleep($termTimeoutSeconds);
        pcntl_signal_dispatch();
        if (!is_null($this->configProcessId)) {
            posix_kill($this->configProcessId, SIGKILL);
        }
    }

    private function startWorkerProcess($id): void
    {
        $job = $this->workersMetadata->getJobById($id);
        $signals = array_keys($this->signalHandlers);
        flush();
        pcntl_sigprocmask(SIG_BLOCK, $signals);
        $pid = pcntl_fork();
        if ($pid === 0) { // child process
            $this->controlMessageHandler->stopMessageListener();
            $this->controlMessageHandler = null;
            foreach ($this->signalHandlers as $signal => $_handler) {
                pcntl_signal($signal, SIG_DFL);
            }
            $childPid = getmypid();
            $workerClassName = $job['config']['workerClass'];
            fwrite(STDOUT, "--> Child process for job $id with PID $childPid initializing Worker $workerClassName" . PHP_EOL);
            $worker = new $workerClassName();
            $workerProcess = new WorkerProcess($worker);
            pcntl_sigprocmask(SIG_UNBLOCK, $signals);
            self::setChildProcessTitle("worker $id");
            $workerProcess->work($job['config']);
            fwrite(STDOUT, "--> Child process for job $id with PID $childPid exiting cleanly" . PHP_EOL);
            exit(self::EXIT_SUCCESS);
        } elseif ($pid > 0) { // parent process
            $this->workersMetadata->updateStartedJob($id, $pid);
            pcntl_sigprocmask(SIG_UNBLOCK, $signals);
            fwrite(STDOUT, "==> Forked a child for job $id with PID $pid" . PHP_EOL);
        } else {
            fwrite(STDERR, "==> Error forking a child for job $id: $pid" . PHP_EOL);
        }
    }

    private function stopProcess($id): bool
    {
        $job = $this->workersMetadata->getJobById($id);
        if (empty($job['state']['pid'])) {
            fwrite(STDERR, "Cannot stop job $id, it is not running" . PHP_EOL);
            return false;
        }
        if ($this->workersMetadata->isStopping($id)) {
            $elapsed = time() - $this->workersMetadata->stopping[$id]['time'];
            fwrite(STDERR, "Job $id received SIGTERM $elapsed seconds ago" . PHP_EOL);
            return true;
        }
        $this->workersMetadata->markAsStopping($id);
        return posix_kill($job['state']['pid'], SIGTERM);
    }

    private function checkStoppingProcesses(): void
    {
        foreach ($this->workersMetadata->stopping as $id => $v) {
            $job = $this->workersMetadata->getJobById($id);
            $timeout = $job['config']['shutdownTimeoutSeconds'];
            $elapsed = time() - $v['time'];
            if ($elapsed >= $timeout) {
                fwrite(STDERR,"Job $id with PID {$v['pid']} shutdown timeout reached after $elapsed seconds, sending SIGKILL" . PHP_EOL);
                posix_kill($v['pid'], SIGKILL);
            }
        }
    }

    private function handleTerminatedChildProcesses(): void
    {
        $pidsToExitCodes = new Map(ProcessUtilities::reapAnyChildren());

        if ($pidsToExitCodes->has($this->configProcessId)) {
            fwrite(STDERR, '==> Config process with PID ' . $this->configProcessId . ' terminated' . PHP_EOL);
            $pidsToExitCodes->remove($this->configProcessId);
            $this->configProcessId = null;
        }

        if ($pidsToExitCodes->nonEmpty()) {
            $pidsToJobIds = $pidsToExitCodes->map(function ($pid, $exitCode) {
                return [$pid, $this->workersMetadata->updateForTerminatedProcess($pid, $exitCode)];
            });
            fwrite(STDOUT, '==> Job workers terminated: ' . implode(', ', $pidsToJobIds->values()) . PHP_EOL);
        }
    }

    private function pollConfigurationSourceForChanges(): void
    {
        if ($this->timeOfLastConfigPoll + $this->configPollIntervalSeconds <= time()) {
            $this->timeOfLastConfigPoll = time();
            try {
                $unvalidatedNewWorkers = $this->pollConfiguration();
                $newWorkers = ConfigurationValidator::filter($unvalidatedNewWorkers);
                $this->workersMetadata->purgeRemovedJobs();
                foreach ($newWorkers as $jobId => $newJobConfig) {
                    if ($this->workersMetadata->has($jobId)) {
                        $oldJob = $this->workersMetadata->getJobById($jobId);
                        if ($newJobConfig['mtime'] > $oldJob['config']['mtime']) {
                            $this->workersMetadata->updateJob($jobId, $newJobConfig);
                        } else {
                            $this->workersMetadata->markAsUnchanged($jobId);
                        }
                    } else {
                        $this->workersMetadata->addNewJob($jobId, $newJobConfig);
                    }
                }
                foreach ($this->workersMetadata->getAll() as $id => $_job) {
                    if (!array_key_exists($id, $newWorkers)) {
                        $this->workersMetadata->removeJob($id);
                    }
                }
                $this->workersMetadata->slateJobStateUpdates();
            } catch (Exception $e) {
                fwrite(STDERR, 'Error getting configuration: ' . $e->getMessage() . PHP_EOL);
            }
        }
    }

    private function initiateRestarts(): void
    {
        if (count($this->workersMetadata->restart) > 0) {
            fwrite(STDOUT,
                   '==> Need to restart '
                   . count($this->workersMetadata->restart)
                   . ' processes: '
                   . implode(', ', $this->workersMetadata->restart->asArray())
                   . PHP_EOL
            );
        }
        foreach ($this->workersMetadata->restart as $id) {
            $this->stopProcess($id);
            $this->workersMetadata->restart->remove($id);
        }
    }

    private function initiateStops(): void
    {
        if (count($this->workersMetadata->stop) > 0) {
            fwrite(STDOUT,
                   '==> Need to stop '
                   . count($this->workersMetadata->stop)
                   . ' processes: '
                   . implode(', ', $this->workersMetadata->stop->asArray())
                   . PHP_EOL
            );
        }
        foreach ($this->workersMetadata->stop as $id) {
            $this->stopProcess($id);
            $this->workersMetadata->stop->remove($id);
        }
    }

    private function initiateStarts(): void
    {
        if (count($this->workersMetadata->start) > 0) {
            fwrite(STDOUT,
                   '==> Need to start '
                   . count($this->workersMetadata->start)
                   . ' processes: '
                   . implode(', ', $this->workersMetadata->start->asArray())
                   . PHP_EOL
            );
        }
        foreach ($this->workersMetadata->start as $id) {
            $this->startWorkerProcess($id);
            $this->workersMetadata->start->remove($id);
        }
    }

    public function run(): void
    {
        self::setSupervisorProcessTitle();
        $this->controlMessageHandler->startMessageListener();

        fwrite(STDOUT, '==> Entering lrpm main loop' . PHP_EOL);
        while ($this->shouldRun) {
            if (is_null($this->configProcessId)) {
                sleep(min(2 * $this->configProcessStarts++, self::CONFIG_PROCESS_MAX_BACKOFF_SECONDS));
                $this->startConfigurationProcess();
                continue;
            }
            $this->pollConfigurationSourceForChanges();
            $this->workersMetadata->slateScheduledRestarts();
            $this->initiateRestarts();
            $this->initiateStops();
            $this->initiateStarts();
            $this->checkStoppingProcesses();
            $this->controlMessageHandler->checkMessages();
            // sleep might get interrupted by a SIGCHLD,
            // so we make sure signal handlers run right after
            sleep($this->secondsBetweenProcessStatePolls);
            pcntl_signal_dispatch();
        }

        fwrite(STDOUT, '==> Entering lrpm shutdown loop' . PHP_EOL);
        fwrite(STDOUT, '==> Terminating config process' . PHP_EOL);
        $this->stopConfigurationProcess();
        fwrite(STDOUT, '==> Initiating shutdown of all worker processes' . PHP_EOL);
        $pids = $this->workersMetadata->getAllPids();
        foreach ($this->workersMetadata->getJobIdsByPids($pids) as $id) {
            $this->stopProcess($id);
        }
        fwrite(STDOUT, '==> Waiting for all child processes to terminate' . PHP_EOL);
        while (count($this->workersMetadata->getAllPids()) > 0) {
            $this->checkStoppingProcesses();
            $this->controlMessageHandler->checkMessages();
            sleep($this->secondsBetweenProcessStatePolls);
            pcntl_signal_dispatch();
        }

        fwrite(STDOUT, '==> lrpm shut down cleanly' . PHP_EOL);
    }

}
