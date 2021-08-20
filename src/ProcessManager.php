<?php

namespace PHPLRPM;

use Exception;

use TIPC\MessageHandler;
use TIPC\UnixSocketStreamServer;

class ProcessManager implements MessageHandler
{
    private const EXIT_SUCCESS = 0;

    private const MAIN_PROC_TAG = '[lrpm main]';

    private $configPollIntervalSeconds;
    private $secondsBetweenProcessStatePolls = 1;

    private $signalHandlers;
    private $workersMetadata;
    private $configurationSource;
    private $messageServer;

    private $timeOfLastConfigPoll = 0;
    private $shouldRun = true;

    public function __construct(ConfigurationSource $configurationSource, int $configPollIntervalSeconds = 30)
    {
        fwrite(STDERR, "lrpm starting" . PHP_EOL);
        $this->configurationSource = $configurationSource;
        $this->configPollIntervalSeconds = $configPollIntervalSeconds;
        $this->workersMetadata = new WorkerMetadata();
        $file = '/run/user/' . posix_geteuid() . '/php-lrpm/socket';
        $this->messageServer =  new UnixSocketStreamServer($file, $this);
        fwrite(STDERR, "Registering signal handlers" . PHP_EOL);
        $this->installSignalHandlers();
    }

    public function handleMessage(string $msg): string
    {
        $help = 'Valid commands: help, status, stop, restart job_id';
        $args = explode(' ', $msg);
        switch ($args[0]) {
            case 'help':
                return "lrpm: $help";
            case 'jsonstatus':
            case 'status':
                return json_encode($this->workersMetadata->getAll());
            case 'stop':
                $this->shouldRun = false;
                return 'lrpm: Shutting down process manager';
            case 'restart':
                return isset($args[1])
                    ? $this->workersMetadata->scheduleRestartOnDemand($args[1])
                    : 'lrpm: restart requires a job id argument';
            default:
                return "lrpm: '$msg' is not a valid command. $help";
        }
    }

    private function installSignalHandlers(): void
    {
        $this->signalHandlers = [
            SIGCHLD => function (int $signo, $_siginfo) {
                fwrite(STDERR, "==> lrpm caught SIGCHLD" . PHP_EOL);
                $this->sigchld_handler($signo);
            },
            SIGTERM => function (int $signo, $_siginfo) {
                fwrite(STDERR, "==> lrpm caught SIGTERM ($signo), initiating LRPM shutdown" . PHP_EOL);
                $this->shouldRun = false;
            },
            SIGINT => function (int $signo, $_siginfo) {
                fwrite(STDERR, "==> lrpm caught SIGINT ($signo), initiating LRPM shutdown" . PHP_EOL);
                $this->shouldRun = false;
            }
        ];

        foreach ($this->signalHandlers as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }
    }

    private function sigchld_handler(int $signo): void
    {
        fwrite(STDOUT, "==> lrpm SIGCHLD handler handling signal $signo" . PHP_EOL);
        $this->reapAndRespawn();
    }

    private static function setMainProcessTitle(): void
    {
        cli_set_process_title(
            'php ' . implode(' ', $_SERVER['argv'])
            . ' ' . self::MAIN_PROC_TAG
        );
    }

    private static function setChildProcessTitle($id): void
    {
        cli_set_process_title(
            preg_replace(
                '/' . preg_quote(self::MAIN_PROC_TAG) . '$/',
                "[lrpm child $id]",
                cli_get_process_title()
            )
        );
    }

    private function startProcess($id): void
    {
        $job = $this->workersMetadata->getJobById($id);
        $signals = array_keys($this->signalHandlers);
        pcntl_sigprocmask(SIG_BLOCK, $signals);
        $pid = pcntl_fork();
        if ($pid === 0) { // child process
            self::setChildProcessTitle($id);
            $childPid = getmypid();
            fwrite(STDOUT, "--> Child process for job $id with PID $childPid starting" . PHP_EOL);
            fwrite(STDOUT, "--> Child process for job $id with PID $childPid setting default signal handlers" . PHP_EOL);
            foreach ($this->signalHandlers as $signal => $_handler) {
                pcntl_signal($signal, SIG_DFL);
            }
            $workerClassName = $job['config']['workerClass'];
            fwrite(STDOUT, "--> Child process for job $id with PID $childPid initializing Worker" . PHP_EOL);
            $worker = new $workerClassName();
            $workerProcess = new WorkerProcess($worker);
            pcntl_sigprocmask(SIG_UNBLOCK, $signals);
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

    private function reapAndRespawn(): void
    {
        $reapResults = ProcessUtilities::reapAnyChildren();
        $pids = array_keys($reapResults);
        $exited = $this->workersMetadata->scheduleRestartOfTerminatedProcesses($pids);
        fwrite(STDOUT, "==> Jobs terminated: " . implode(', ', $exited) . PHP_EOL);
    }

    private function pollConfigurationSourceForChanges(): void
    {
        if ($this->timeOfLastConfigPoll + $this->configPollIntervalSeconds <= time()) {
            $this->timeOfLastConfigPoll = time();
            try {
                $unvalidatedNewWorkers = $this->configurationSource->loadConfiguration();
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
                fwrite(STDERR, "Error getting jobs configuration" . PHP_EOL);
            }
        }
    }

    private function processRestarts(): void
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

    private function processStops(): void
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

    private function processStarts(): void
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
            $this->startProcess($id);
            $this->workersMetadata->start->remove($id);
        }
    }

    public function run(): void
    {
        self::setMainProcessTitle();

        fwrite(STDOUT, "Starting control message listener service" . PHP_EOL);
        $this->messageServer->listen();

        fwrite(STDOUT, "Entering lrpm main loop" . PHP_EOL);
        while ($this->shouldRun) {
            $this->pollConfigurationSourceForChanges();
            $this->workersMetadata->slateScheduledRestarts();
            $this->processRestarts();
            $this->processStops();
            $this->processStarts();
            $this->checkStoppingProcesses();
            $this->messageServer->checkMessages();
            // sleep might get interrupted by a SIGCHLD,
            // so we make sure signal handlers run right after
            sleep($this->secondsBetweenProcessStatePolls);
            pcntl_signal_dispatch();
        }

        fwrite(STDOUT, "Entering lrpm shutdown loop" . PHP_EOL);
        while (count($pids = $this->workersMetadata->getAllPids()) > 0) {
            $ids = $this->workersMetadata->getJobIdsByPids($pids);
            foreach ($ids as $id) {
                $this->stopProcess($id);
            }
            $this->checkStoppingProcesses();
            $this->messageServer->checkMessages();
            sleep($this->secondsBetweenProcessStatePolls);
            pcntl_signal_dispatch();
        }

        fwrite(STDOUT, "lrpm shut down cleanly" . PHP_EOL);
    }

}
