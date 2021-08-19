<?php

namespace PHPLRPM;

use Exception;

use TIPC\MessageHandler;
use TIPC\UnixSocketStreamServer;

class ProcessManager implements MessageHandler
{
    private const EXIT_SUCCESS = 0;

    private $secondsBetweenConfigPolls = 30; // TODO configuration
    private $secondsBetweenProcessStatePolls = 1;

    private $workersMetadata;
    private $configurationSource;
    private $messageServer;

    private $timeOfLastConfigPoll = 0;
    private $shouldRun = true;

    public function __construct(ConfigurationSource $configurationSource)
    {
        fwrite(STDERR, "lrpm starting" . PHP_EOL);
        $this->configurationSource = $configurationSource;
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
        pcntl_signal(SIGCHLD, function (int $signo, $_siginfo) {
            fwrite(STDERR, "==> Caught SIGCHLD" . PHP_EOL);
            $this->sigchld_handler($signo);
        });
        pcntl_signal(SIGTERM, function (int $signo, $_siginfo) {
            fwrite(STDERR, "==> Caught SIGTERM ($signo), initiating LRPM shutdown" . PHP_EOL);
            $this->shouldRun = false;
        });
        pcntl_signal(SIGINT, function (int $signo, $_siginfo) {
            fwrite(STDERR, "==> Caught SIGINT ($signo), initiating LRPM shutdown" . PHP_EOL);
            $this->shouldRun = false;
        });
    }

    private function sigchld_handler(int $signo): void
    {
        fwrite(STDOUT, "==> SIGCHLD handler handling signal " . $signo . PHP_EOL);
        $this->reapAndRespawn();
    }

    private function startProcess($id): void
    {
        $job = $this->workersMetadata->getJobById($id);
        $pid = pcntl_fork();
        if ($pid === 0) { // child process
            fwrite(STDOUT, '--> Child process starting' . PHP_EOL);
            $workerClassName = $job['config']['workerClass'];
            $worker = new $workerClassName();
            $workerProcess = new WorkerProcess($worker);
            $workerProcess->work($job['config']);
            fwrite(STDOUT, '--> Child process exiting' . PHP_EOL);
            exit(self::EXIT_SUCCESS);
        } elseif ($pid > 0) { // parent process
            fwrite(STDOUT, '==> Forked a child with PID ' . $pid . PHP_EOL);
            $this->workersMetadata->updateStartedJob($id, $pid);
        } else {
            fwrite(STDERR, '==> Error forking child process: ' . $pid . PHP_EOL);
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
        $timeout = 10; // TODO configuration
        foreach ($this->workersMetadata->stopping as $id => $v) {
            $elapsed = time() - $v['time'];
            if ($elapsed >= $timeout) {
                fwrite(STDERR,"id $id with PID {$v['pid']} stopping for $elapsed seconds, sending SIGKILL" . PHP_EOL);
                posix_kill($v['pid'], SIGKILL);
            }
        }
    }

    private function reapAndRespawn(): void
    {
        $reapResults = ProcessUtilities::reapAnyChildren();
        $pids = array_keys($reapResults);
        $exited = $this->workersMetadata->scheduleRestartOfTerminatedProcesses($pids);
        fwrite(STDOUT, "==> Jobs terminated: " . implode(',', $exited) . PHP_EOL);
    }

    private function pollConfigurationSourceForChanges(): void
    {
        if ($this->timeOfLastConfigPoll + $this->secondsBetweenConfigPolls <= time()) {
            $this->timeOfLastConfigPoll = time(); // TODO wait a full cycle even when db is not reachable
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

    public function run(): void
    {
        fwrite(STDOUT, "Starting control message listener service" . PHP_EOL);
        $this->messageServer->listen();

        fwrite(STDOUT, "Entering lrpm main loop" . PHP_EOL);
        while ($this->shouldRun) {
            $this->pollConfigurationSourceForChanges();

            if (count($this->workersMetadata->restart) > 0) {
                fwrite(STDOUT,'==> Need to restart ' . count($this->workersMetadata->restart) . ' processes' . PHP_EOL);
            }
            foreach ($this->workersMetadata->restart as $id) {
                $this->stopProcess($id);
                $this->workersMetadata->restart->remove($id);
            }
            if (count($this->workersMetadata->stop) > 0) {
                fwrite(STDOUT, '==> Need to stop ' . count($this->workersMetadata->stop) . ' processes' . PHP_EOL);
            }
            foreach ($this->workersMetadata->stop as $id) {
                $this->stopProcess($id);
                $this->workersMetadata->stop->remove($id);
            }
            if (count($this->workersMetadata->start) > 0) {
                fwrite(STDOUT, '==> Need to start ' . count($this->workersMetadata->start) . ' processes' . PHP_EOL);
            }
            foreach ($this->workersMetadata->start as $id) {
                $this->startProcess($id);
                $this->workersMetadata->start->remove($id);
            }

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
            sleep($this->secondsBetweenProcessStatePolls);
            pcntl_signal_dispatch();
        }

        fwrite(STDOUT, "lrpm shut down cleanly" . PHP_EOL);
    }

}
