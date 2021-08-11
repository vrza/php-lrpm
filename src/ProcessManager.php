<?php

namespace PHPLRPM;

use Exception;

use TIPC\MessageHandler;
use TIPC\UnixSocketStreamServer;

class ProcessManager implements MessageHandler
{
    private const EXIT_SUCCESS = 0;

    private $workersMetadata;
    private $timeOfLastConfigPoll = 0;
    private $secondsBetweenConfigPolls = 10;
    private $secondsBetweenProcessStatePolls = 1;

    private $configurationSource;

    private $messageServer;
    private $shouldRun = true;

    public function __construct(ConfigurationSource $configurationSource)
    {
        $this->configurationSource = $configurationSource;
        $this->workersMetadata = new WorkerMetadata();
        pcntl_signal(SIGCHLD, function (int $signo, $_siginfo) {
            fwrite(STDOUT, "==> Caught SIGCHLD" . PHP_EOL);
            $this->sigchld_handler($signo);
        });

        $file = '/run/user/' . posix_geteuid() . '/php-lrpm/socket';
        $this->messageServer =  new UnixSocketStreamServer($file, $this);
    }

    public function handleMessage(string $msg): string
    {
        $help = 'Valid commands: help, status, stop';
        switch ($msg) {
            case 'help':
                return "lrpm: $help";
            case 'status':
                return json_encode($this->workersMetadata->getAll());
            case 'stop':
                $this->shouldRun = false;
                return 'lrpm: Shutting down process manager';
            default:
                return "lrpm: '$msg' is not a valid command. $help";
        }
    }

    private function sigchld_handler(int $signo): void
    {
        fwrite(STDOUT, "==> SIGCHLD handler handling signal " . $signo . PHP_EOL);
        $this->reapAndRespawn();
    }

    private function startProcess($id): void
    {
        $job = $this->workersMetadata->getById($id);
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

    private function stopProcess($id): void
    {
        $job = $this->workersMetadata->getById($id);
        if (empty($job['state']['pid'])) {
            fwrite(STDERR, 'Cannot stop job ' . $id . ', it is not running' . PHP_EOL);
            return;
        }
        posix_kill($job['state']['pid'],SIGTERM);
    }

    private function reapAndRespawn(): void
    {
        $reapResults = ProcessUtilities::reapAnyChildren();
        $pids = array_keys($reapResults);
        $exited = $this->workersMetadata->scheduleRestartsByPIDs($pids);
        fwrite(STDOUT, "==> Jobs terminated: " . implode(',', $exited) . PHP_EOL);
        //var_dump($exited);
        //var_dump($this->workersMetadata->getAll());
        /*
        $jobsToRespawn = array_filter($exited, function ($id): bool { return $this->workersMetadata->has($id); });
        fwrite(STDOUT, "==> Respawning jobs: " . implode(',', $jobsToRespawn) . PHP_EOL);
        foreach ($jobsToRespawn as $id) {
            $this->workersMetadata->start[$id] = $this->workersMetadata->getById($id);
        }
        */
    }

    private function pollDbForConfigChanges(): void
    {
        if ($this->timeOfLastConfigPoll + $this->secondsBetweenConfigPolls <= time()) {
            $this->timeOfLastConfigPoll = time(); // TODO wait a full cycle even when db is not reachable
            try {
                $newWorkers = $this->configurationSource->loadConfiguration();
                //fwrite(STDOUT, "==> Loaded fresh configuration:" . PHP_EOL);
                //var_dump($newWorkers);
                $this->workersMetadata->purgeRemovedJobs();
                foreach ($newWorkers as $jobId => $newJobConfig) {
                    if ($this->workersMetadata->has($jobId)) {
                        $oldJob = $this->workersMetadata->getById($jobId);
                        if ($newJobConfig['mtime'] > $oldJob['config']['mtime']) {
                            $this->workersMetadata->updateJob($jobId, $newJobConfig);
                        } else {
                            $this->workersMetadata->markAsUnchanged($jobId);
                        }
                    } else {
                        $this->workersMetadata->addNewJob($jobId, $newJobConfig);
                    }
                }
                foreach ($this->workersMetadata->getAll() as $id => $job) {
                    if (!array_key_exists($id, $newWorkers)) {
                        $this->workersMetadata->removeJob($id);
                    }
                }
                //fwrite(STDOUT, "==> Internal state after diff:" . PHP_EOL);
                //var_dump($this->workersMetadata->getAll());
                $this->workersMetadata->updateStateSyncMap();
            } catch (Exception $e) {
                fwrite(STDERR, "Error getting jobs configuration" . PHP_EOL);
            }
        }
    }

    public function run(): void
    {
        $this->messageServer->listen();
        // process manager main loop
        while ($this->shouldRun) {
            $this->pollDbForConfigChanges();
            if (count($this->workersMetadata->restart) > 0) {
                fwrite(STDOUT,'==> Need to restart ' . count($this->workersMetadata->restart) . ' processes' . PHP_EOL);
            }
            foreach ($this->workersMetadata->restart as $id => $job) {
                $this->stopProcess($id);
                unset($this->workersMetadata->restart[$id]);
            }
            if (count($this->workersMetadata->stop) > 0) {
                fwrite(STDOUT, '==> Need to stop ' . count($this->workersMetadata->stop) . ' processes' . PHP_EOL);
            }
            foreach ($this->workersMetadata->stop as $id => $job) {
                $this->stopProcess($id);
                unset($this->workersMetadata->stop[$id]);
            }
            if (count($this->workersMetadata->start) > 0) {
                fwrite(STDOUT, '==> Need to start ' . count($this->workersMetadata->start) . ' processes' . PHP_EOL);
            }
            foreach ($this->workersMetadata->start as $id => $job) {
                $this->startProcess($id);
                unset($this->workersMetadata->start[$id]);
            }
            //fwrite(STDOUT, "Workers metadata before cleanup:" . PHP_EOL);
            //var_dump($this->workersMetadata->getAll());
            pcntl_signal_dispatch();
            //fwrite(STDOUT, "Workers metadata after cleanup:" . PHP_EOL);
            //var_dump($this->workersMetadata->getAll());

            $this->messageServer->checkMessages();

            sleep($this->secondsBetweenProcessStatePolls);
        }

        fwrite(STDERR, "Clean shutdown." . PHP_EOL);
    }

}
