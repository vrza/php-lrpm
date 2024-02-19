<?php

namespace PHPLRPM;

use Exception;
use RuntimeException;
use CardinalCollections\Mutable\Map;
use PHPLRPM\Serialization\Serializer;
use PHPLRPM\Serialization\JSONSerializer;
use VladimirVrzic\Simplogger\StdoutLogger;

class ProcessManager
{
    private const SUPERVISOR_PROCESS_TAG = '[lrpm supervisor]';

    private $shouldRun = true;

    private $configurationSourceClass;
    private $configProcessManager;
    private $newConfig = null;
    private $configPollIntervalSeconds;
    private $lastSigUsr1Info = [];

    private $workersMetadata;
    private $secondsBetweenProcessStatePolls = 1;

    private $configMessageHandler;
    private $controlMessageHandler;
    private $messageService;
    private $serializer;

    public function __construct(
        string $configurationSourceClass,
        int $configPollIntervalSeconds = ConfigurationProcess::DEFAULT_CONFIG_POLL_INTERVAL,
        ?Serializer $serializer = null
    )
    {
        $logger = new StdoutLogger(TRUE, TRUE, 'lrpm');
        Log::setInstance($logger);

        Log::getInstance()->info('==> lrpm starting');
        $this->configPollIntervalSeconds = $configPollIntervalSeconds;
        $this->workersMetadata = new WorkerMetadata();
        $this->serializer = $serializer ?? new JSONSerializer();
        $this->controlMessageHandler = new ControlMessageHandler($this);
        $this->configMessageHandler = new ConfigurationMessageHandler($this, $this->serializer);
        $this->messageService = new MessageService($this->configMessageHandler, $this->controlMessageHandler);
        $this->configurationSourceClass = $configurationSourceClass;
        $this->configProcessManager = new ConfigurationProcessManager();
        Log::getInstance()->info('==> Registering supervisor signal handlers');
        $this->installSignalHandlers();
    }

    private function configInitCleanup()
    {
        $this->messageService->destroyMessageServer();
        $this->messageService = null;
        $this->controlMessageHandler = null;
        $this->configMessageHandler = null;
        $this->configProcessManager = null;
        $this->workersMetadata = null;
    }

    private function workerInitCleanup()
    {
        $this->messageService->destroyMessageServer();
        $this->messageService = null;
        $this->controlMessageHandler = null;
        $this->configMessageHandler = null;
        $this->configProcessManager = null;
        $this->workersMetadata = null;
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
        $this->configProcessManager->sendSignalToConfigProcess(SIGHUP);
    }

    public function scheduleRestartOnDemand($jobId): string
    {
        return $this->workersMetadata->scheduleRestartOnDemand($jobId);
    }

    private function getSignalHandlers(): array
    {
        return [
            SIGCHLD => function (int $signo, $_siginfo) {
                Log::getInstance()->info("==> Supervisor caught SIGCHLD ($signo)");
                $this->handleTerminatedChildProcesses();
            },
            SIGTERM => function (int $signo, $_siginfo) {
                Log::getInstance()->notice("==> Supervisor caught SIGTERM ($signo), initiating lrpm shutdown");
                $this->shutdown();
            },
            SIGINT => function (int $signo, $_siginfo) {
                Log::getInstance()->notice("==> Supervisor caught SIGINT ($signo), initiating lrpm shutdown");
                $this->shutdown();
            },
            SIGUSR1 => function (int $signo, $siginfo) {
                Log::getInstance()->info("==> Supervisor caught SIGUSR1 ($signo)");
                $this->setLastSigUsr1Info($siginfo);
            }
        ];
    }

    private function installSignalHandlers(): void
    {
        foreach ($this->getSignalHandlers() as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }
    }

    public function setLastSigUsr1Info($siginfo)
    {
        $this->lastSigUsr1Info = $siginfo;
    }

    public function getLastSigUsr1Pid(): int
    {
        return $this->lastSigUsr1Info['pid'] ?? 0;
    }

    private static function setSupervisorProcessTitle(): void
    {
        cli_set_process_title(
            'php ' . implode(' ', $_SERVER['argv'])
            . ' ' . self::SUPERVISOR_PROCESS_TAG
        );
    }

    public static function setChildProcessTitle($tag): void
    {
        cli_set_process_title(
            preg_replace(
                '/' . preg_quote(self::SUPERVISOR_PROCESS_TAG) . '$/',
                "[lrpm $tag]",
                cli_get_process_title()
            )
        );
    }

    public function setNewConfig(array $config): void
    {
        $this->newConfig = $config;
    }

    private function startConfigurationProcess(): void
    {
        $supervisorPid = getmypid();
        $signals = array_keys($this->getSignalHandlers());
        flush();
        pcntl_sigprocmask(SIG_BLOCK, $signals);
        $pid = pcntl_fork();
        if ($pid === 0) { // child process
            $this->configInitCleanup();
            foreach ($this->getSignalHandlers() as $signal => $_handler) {
                pcntl_signal($signal, SIG_DFL);
            }
            pcntl_sigprocmask(SIG_UNBLOCK, $signals);
            $configPid = getmypid();
            Log::getInstance()->info("--> Config process with PID $configPid running");
            self::setChildProcessTitle('config');
            $configurationService = new ConfigurationProcess($this->configurationSourceClass, $this->configPollIntervalSeconds, $this->serializer);
            $configurationService->runConfigurationProcessLoop($supervisorPid);
        } elseif ($pid > 0) { // parent process
            pcntl_sigprocmask(SIG_UNBLOCK, $signals);
            $this->configProcessManager->setPID($pid);
            Log::getInstance()->info("==> Forked config process with PID: $pid");
            Log::getInstance()->info("==> Waiting for config service to let us know it's ready");
            $remaining = 60;
            while ($this->getLastSigUsr1Pid() !== $pid && $this->shouldRun && $remaining > 0) {
                $remaining = sleep($remaining);
                pcntl_signal_dispatch();
            }
            if (!$this->shouldRun) {
                return;
            }
            if ($remaining == 0) {
                throw new RuntimeException('Config process did not send readiness notification');
            }
            Log::getInstance()->info('==> Supervisor received readiness notification from config process');
        } else {
            throw new RuntimeException("Error forking config process: $pid");
        }
    }

    private function startWorkerProcess($id): void
    {
        $job = $this->workersMetadata->getJobById($id);
        $signals = array_keys($this->getSignalHandlers());
        flush();
        pcntl_sigprocmask(SIG_BLOCK, $signals);
        $pid = pcntl_fork();
        if ($pid === 0) { // child process
            $this->workerInitCleanup();
            foreach ($this->getSignalHandlers() as $signal => $_handler) {
                pcntl_signal($signal, SIG_DFL);
            }
            $childPid = getmypid();
            $workerClassName = $job['config']['workerClass'];
            Log::getInstance()->info("--> Child process for job $id with PID $childPid initializing Worker ($workerClassName)");
            $workerProcess = new WorkerProcess($workerClassName);
            pcntl_sigprocmask(SIG_UNBLOCK, $signals);
            self::setChildProcessTitle("worker $id");
            $workerProcess->work($job['config']);
            Log::getInstance()->info("--> Child process for job $id with PID $childPid exiting cleanly");
            exit(ExitCodes::EXIT_SUCCESS);
        } elseif ($pid > 0) { // parent process
            $this->workersMetadata->updateStartedJob($id, $pid);
            pcntl_sigprocmask(SIG_UNBLOCK, $signals);
            Log::getInstance()->info("==> Forked a child for job $id with PID $pid");
        } else {
            Log::getInstance()->error("==> Error forking a child for job $id: $pid");
        }
    }

    private function stopWorkerProcess($id): bool
    {
        $job = $this->workersMetadata->getJobById($id);
        if (empty($job['state']['pid'])) {
            Log::getInstance()->warning("Cannot stop job $id, it is not running");
            return false;
        }
        if ($this->workersMetadata->isStopping($id)) {
            $elapsed = time() - $this->workersMetadata->stopping[$id]['time'];
            Log::getInstance()->info("Job $id received SIGTERM $elapsed seconds ago");
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
                Log::getInstance()->notice("Job $id with PID {$v['pid']} shutdown timeout reached after $elapsed seconds, sending SIGKILL");
                posix_kill($v['pid'], SIGKILL);
            }
        }
    }

    private function handleTerminatedChildProcesses(): void
    {
        $pidsToExitStatuses = new Map(ProcessUtilities::reapAnyChildren());

        $configPID = $this->configProcessManager->getPID();
        if ($pidsToExitStatuses->has($configPID)) {
            Log::getInstance()->info("==> Config process with PID $configPID terminated");
            $pidsToExitStatuses->remove($configPID);
            $this->configProcessManager->handleTerminatedConfigProcess();
        }

        if ($pidsToExitStatuses->nonEmpty()) {
            $pidsToJobIds = $pidsToExitStatuses->map(function ($pid, $exitStatus) {
                return [$pid, $this->workersMetadata->updateForTerminatedProcess($pid, $exitStatus)];
            });
            Log::getInstance()->info('==> Job workers terminated: ' . implode(', ', $pidsToJobIds->values()));
        }
    }

    private function updateConfiguration(): void
    {
        if (!is_null($this->newConfig)) {
            try {
                $newWorkers = ConfigurationValidator::filter($this->newConfig);
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
                $this->newConfig = null;
            } catch (Exception $e) {
                Log::getInstance()->error('Error updating configuration: ' . $e->getMessage());
            }
        }
    }

    private function initiateRestarts(): void
    {
        if (count($this->workersMetadata->restart) > 0) {
            Log::getInstance()->info(
                   '==> Need to restart '
                   . count($this->workersMetadata->restart)
                   . ' processes: '
                   . implode(', ', $this->workersMetadata->restart->asArray())
            );
        }
        foreach ($this->workersMetadata->restart as $id) {
            $this->stopWorkerProcess($id);
            $this->workersMetadata->restart->remove($id);
        }
    }

    private function initiateStops(): void
    {
        if (count($this->workersMetadata->stop) > 0) {
            Log::getInstance()->info(
                   '==> Need to stop '
                   . count($this->workersMetadata->stop)
                   . ' processes: '
                   . implode(', ', $this->workersMetadata->stop->asArray())
            );
        }
        foreach ($this->workersMetadata->stop as $id) {
            $this->stopWorkerProcess($id);
            $this->workersMetadata->stop->remove($id);
        }
    }

    private function initiateStarts(): void
    {
        if (count($this->workersMetadata->start) > 0) {
            Log::getInstance()->info(
                   '==> Need to start '
                   . count($this->workersMetadata->start)
                   . ' processes: '
                   . implode(', ', $this->workersMetadata->start->asArray())
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
        $this->messageService->startMessageListener();

        Log::getInstance()->info('==> Entering lrpm main loop');
        while ($this->shouldRun) {
            $retry = $this->configProcessManager->shouldRetryStartingConfigProcess();
            if ($retry) {
                $this->startConfigurationProcess();
            } elseif (is_null($retry)) {
                break;
            }
            $this->updateConfiguration();
            $this->workersMetadata->slateScheduledRestarts();
            $this->initiateRestarts();
            $this->initiateStops();
            $this->initiateStarts();
            $this->checkStoppingProcesses();
            $this->messageService->checkMessages($this->secondsBetweenProcessStatePolls);
            pcntl_signal_dispatch();
        }

        Log::getInstance()->info('==> Entering lrpm shutdown loop');
        Log::getInstance()->info('==> Terminating config process');
        $this->configProcessManager->stopConfigurationProcess();
        Log::getInstance()->info('==> Initiating shutdown of all worker processes');
        $pids = $this->workersMetadata->getAllPids();
        foreach ($this->workersMetadata->getJobIdsByPids($pids) as $id) {
            $this->stopWorkerProcess($id);
        }
        Log::getInstance()->info('==> Waiting for all child processes to terminate');
        while (count($this->workersMetadata->getAllPids()) > 0) {
            $this->checkStoppingProcesses();
            $this->messageService->checkMessages(0, 50000);
            pcntl_signal_dispatch();
            pcntl_signal_dispatch();
        }

        Log::getInstance()->info('==> lrpm shut down cleanly');
    }

}
