<?php


namespace PHPLRPM;


class ProcessManager {
    const EXIT_SUCCESS = 0;

    private $workersMetadata;
    private $timeOfLastConfigPoll = 0;
    private $secondsBetweenConfigPolls = 10;
    private $secondsBetweenProcessStatePolls = 2;

    private $configurationSource;

    private $start = [];
    private $stop = [];
    private $restart = [];

    public function __construct(ConfigurationSource $configurationSource) {
        $this->configurationSource = $configurationSource;
        $this->workersMetadata = new WorkerMetadata();
        pcntl_signal(SIGCHLD, function ($signal) {
            fwrite(STDOUT, "==> Caught SIGCHLD" . PHP_EOL);
            $this->sigchld_handler($signal);
        });
    }

    private function sigchld_handler($signal) {
        fwrite(STDOUT, "=> SIGCHLD handler " . $signal . PHP_EOL);
        $this->reapAndRespawn();
    }

    private function startProcesses($jobs) {
        fwrite(STDOUT, '==> Need to start ' . count($jobs) . ' processes' . PHP_EOL);
        foreach ($jobs as $id) {
            $this->startProcess($id);
        }
    }

    private function startProcess($id) {
        $job = $this->workersMetadata->getById($id);
        $pid = pcntl_fork();
        if ($pid === 0) { // child process
            fwrite(STDOUT, '--> A child is born' . PHP_EOL);
            $workerClassName = $job['config']['workerClass'];
            $worker = new $workerClassName;
            $workerProcess = new WorkerProcess($worker);
            $workerProcess->work($job['config']);
            fwrite(STDOUT, '--> Child exiting' . PHP_EOL);
            exit(self::EXIT_SUCCESSS);
        } else if ($pid > 0) { // parent process
            fwrite(STDOUT, '==> Forked a child with PID ' . $pid . PHP_EOL);
            $this->workersMetadata->updateStartedJob($id, $pid);
        } else {
            fwrite(STDERR, '==> Error forking child process: ' . $pid . PHP_EOL);
        }
    }

    private function stopProcesses($jobs) {
        fwrite(STDOUT, '==> Need to stop ' . count($jobs) . ' processes' . PHP_EOL);
        foreach ($jobs as $id) {
            $this->stopProcess($id);
        }
    }

    private function stopProcess($id) {
        $job = $this->workersMetadata->getById($id);
        if (empty($job['state']['pid'])) {
            fwrite(STDERR, 'Cannot stop job ' . $id . ', it is not running' . PHP_EOL);
            return;
        };
        posix_kill($job['state']['pid'],SIGTERM);
    }

    private function reapAndRespawn() {
        $reapResults = ProcessUtilities::reapAnyChildren();
        $pids = array_keys($reapResults);
        $exited = $this->workersMetadata->scheduleRestartsByPIDs($pids);
        fwrite(STDOUT, "Exited:" . PHP_EOL);
        var_dump($exited);
        var_dump($this->workersMetadata->getAll());
        fwrite(STDOUT, '================================================================' . PHP_EOL);
        flush();
        $jobsToRespawn = array_filter($exited, function ($id) { return $this->workersMetadata->has($id); });
        fwrite(STDOUT, "==> Respawning jobs:" . PHP_EOL);
        var_dump($jobsToRespawn);
        //$this->startProcesses($jobsToRespawn);
        foreach ($jobsToRespawn as $id) {
            $this->workersMetadata->start[$id] = $this->workersMetadata->getById($id);
        }
    }

    private function pollDbForConfigChanges() {
        if ($this->timeOfLastConfigPoll + $this->secondsBetweenConfigPolls <= time()) {
            $this->timeOfLastConfigPoll = time(); // TODO wait a full cycle even when db is not reachable
            try {
                $newWorkers = $this->configurationSource->loadConfiguration();
                fwrite(STDOUT, "==> Loaded fresh configuration:" . PHP_EOL);
                var_dump($newWorkers);
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
                fwrite(STDOUT, "==> Internal state after diff:" . PHP_EOL);
                var_dump($this->workersMetadata->getAll());
                $this->workersMetadata->updateStateSyncMap();
            } catch (Exception $e) {
                fwrite(STDERR, "Error getting jobs configuration" . PHP_EOL);
            }
        }
    }

    public function run() {
        // process manager main loop
        while (true) {
            $this->pollDbForConfigChanges();
            fwrite(STDOUT, '==> Need to restart ' . count($this->workersMetadata->restart) . ' processes' . PHP_EOL);
            foreach ($this->workersMetadata->restart as $id => $job) {
                $this->stopProcess($id);
                unset($this->workersMetadata->restart[$id]);
            }
            fwrite(STDOUT, '==> Need to stop ' . count($this->workersMetadata->stop) . ' processes' . PHP_EOL);
            foreach ($this->workersMetadata->stop as $id => $job) {
                $this->stopProcess($id);
                unset($this->workersMetadata->stop[$id]);
            }
            fwrite(STDOUT, '==> Need to start ' . count($this->workersMetadata->start) . ' processes' . PHP_EOL);
            foreach ($this->workersMetadata->start as $id => $job) {
                $this->startProcess($id);
                unset($this->workersMetadata->start[$id]);
            }
            //fwrite(STDOUT, "Workers metadata before cleanup:" . PHP_EOL);
            //var_dump($this->workersMetadata->getAll());
            pcntl_signal_dispatch();
            //fwrite(STDOUT, "Workers metadata after cleanup:" . PHP_EOL);
            //var_dump($this->workersMetadata->getAll());
            flush();
            sleep($this->secondsBetweenProcessStatePolls);
        }
    }

}
