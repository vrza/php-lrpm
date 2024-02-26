<?php

namespace PHPLRPM;

use InvalidArgumentException;
use CardinalCollections\ArrayWithSecondaryKeys\ArrayWithSecondaryKeys;
use CardinalCollections\Mutable\Set;

/*
 * $id => [
 *     'config' => []; // user-defined Worker configuration
 *     'state' => [
 *         'pid' => (int)
 *         'restartAt' => (int)
 *         'backoffInterval' => (int) // 1, 2, 4, 8 ...
 *         'cfState', // ADDED | REMOVED | UNCHANGED since last config update
 *         'lastExitStatus'
 *     ]
 * ]
 */
class WorkerMetadata {
    const UNCHANGED = 0;
    const ADDED = 1;
    const REMOVED = 2;
    const UPDATED = 3;

    const DEFAULT_BACKOFF = 1;
    const MAX_BACKOFF = 60 * 60 * 6;
    const BACKOFF_MULTIPLIER = 2;

    const PID_KEY = 'state.pid';

    private $metadata;

    public $start;
    public $stop;
    public $restart;

    public $stopping = [];

    public function __construct()
    {
        $this->metadata = new ArrayWithSecondaryKeys();
        $this->metadata->createIndex(self::PID_KEY);
        $this->start = new Set();
        $this->stop = new Set();
        $this->restart = new Set();
    }

    public function getAll(): array
    {
        return $this->metadata->asArray();
    }

    public function has($id): bool
    {
        return $this->metadata->containsPrimaryKey($id);
    }

    public function getJobById($id): array
    {
        if (!$this->has($id)) {
            throw new InvalidArgumentException("Unknown job id: $id" . PHP_EOL);
        }
        return $this->metadata->get($id);
    }

    private function removePid(int $pid)
    {
        return $this->metadata->updateSecondaryKey(self::PID_KEY, $pid, null);
    }

    public function updateForTerminatedProcess(int $pid, int $exitStatus)
    {
        $id = $this->metadata->getPrimaryKeyByIndex(self::PID_KEY, $pid);
        $this->metadata->put("$id.state.lastExitStatus", $exitStatus);
        $this->scheduleRestartWithBackoff($id);
        $this->unmarkAsStopping($id);
        return $this->removePid($pid);
    }

    private function scheduleRestartWithBackoff($id)
    {
        if (!$this->has($id)) {
            Log::getInstance()->warning("Will not restart job $id, it does not exist");
            return $id;
        }
        if ($this->metadata->get("$id.state.cfState") == self::REMOVED) {
            Log::getInstance()->info("Will not restart job $id, it was removed");
            return $id;
        }
        $job = $this->metadata->get($id);
        $now = time();
        if ($now < $job['state']['startedAt'] + $job['config']['shortRunTimeSeconds']) {
            $job['state']['restartAt'] = $now + $job['state']['backoffInterval'];
            $job['state']['backoffInterval'] *= self::BACKOFF_MULTIPLIER;
            if ($job['state']['backoffInterval'] > self::MAX_BACKOFF) {
                $job['state']['backoffInterval'] = self::MAX_BACKOFF;
            }
            Log::getInstance()->info("Job $id run time was too short, backing off for seconds: {$job['state']['backoffInterval']}");
        } else {
            Log::getInstance()->info("Job $id run time was longer than {$job['config']['shortRunTimeSeconds']} seconds, resetting backoff");
            $job['state']['restartAt'] = $now;
            $job['state']['backoffInterval'] = self::DEFAULT_BACKOFF;
        }
        Log::getInstance()->info(date('c', time()) . " Job $id scheduled to restart at " . date('c', $job['state']['restartAt']));
        $this->metadata->put($id, $job);
        return $id;
    }

    public function scheduleRestartOnDemand($id): string
    {
        if (!$this->has($id)) {
            return("Will not restart job $id, it does not exist");
        }
        if ($this->metadata->get("$id.state.cfState") == self::REMOVED) {
            return("Will not restart job $id, it was removed");
        }
        $job = $this->metadata->get($id);
        $job['state']['restartAt'] = time();
        $job['state']['backoffInterval'] = self::DEFAULT_BACKOFF;
        if (!empty($job['state']['pid'])) {
            $this->restart->add($id);
        } else {
            $this->start->add($id);
        }
        $this->metadata->put($id, $job);
        return("Scheduled immediate restart of job $id");
    }

    public function updateStartedJob($id, int $pid)
    {
        $job = $this->getJobById($id);
        $job['state']['startedAt'] = time();
        $job['state']['pid'] = $pid;
        $this->metadata->put($id, $job);
        return $id;
    }

    public function getAllPids(): array
    {
        return $this->metadata->secondaryKeys(self::PID_KEY);
    }

    public function getJobIdsByPids(array $pids): array
    {
        return array_map(function ($pid) { return $this->getJobIdByPid($pid); }, $pids);
    }

    public function getJobIdByPid(int $pid)
    {
        if (empty($pid) || !is_int($pid) || $pid < 1) {
            throw new InvalidArgumentException("Process ID must be a positive integer");
        }
        return $this->metadata->getPrimaryKeyByIndex(self::PID_KEY, $pid);
    }

    public function setCfState($id, int $cfState): void
    {
        if (!$this->has($id)) {
            throw new InvalidArgumentException("Cannot update job with id $id to configuration state $cfState, id does not exist" . PHP_EOL);
        }
        $this->metadata->put("$id.state.cfState", $cfState);
    }

    public function markAsUnchanged($id)
    {
        $this->setCfState($id, WorkerMetadata::UNCHANGED);
    }

    public function markAsStopping($id)
    {
        $job = $this->getJobById($id);
        if (!empty($job['state']['pid'])) {
            Log::getInstance()->info("Marking job $id with pid {$job['state']['pid']} as stopping");
            $this->stopping[$id]['pid'] = $job['state']['pid'];
            $this->stopping[$id]['time'] = time();
        } else {
            Log::getInstance()->warning("Job $id is not running, cannot mark it as stopping");
        }
    }

    private function unmarkAsStopping($id)
    {
        if (!$this->has($id)) {
            throw new InvalidArgumentException("Cannot unmark job with id $id as stopping, id does not exist" . PHP_EOL);
        }
        if (isset($this->stopping[$id])) {
            Log::getInstance()->info("Unmarking job $id as stopping");
            unset($this->stopping[$id]);
        } else {
            Log::getInstance()->info("Job $id was not terminated by our signal");
        }
    }

    public function isStopping($id): bool
    {
        if (!$this->has($id)) {
            throw new InvalidArgumentException("Cannot check if job with $id is stopping, id does not exist" . PHP_EOL);
        }
        return isset($this->stopping[$id]);
    }

    public function updateJob($id, array $config)
    {
        Log::getInstance()->info("Setting fresh config for job $id");
        $job = $this->getJobById($id);
        $job['config'] = $config;
        $job['state']['restartAt'] = time();
        Log::getInstance()->info("Resetting backoff for job $id");
        $job['state']['backoffInterval'] = self::DEFAULT_BACKOFF;
        $job['state']['cfState'] = self::UPDATED;
        $this->metadata->put($id, $job);
        return $id;
    }

    public function addNewJob($id, array $config)
    {
        Log::getInstance()->info("Adding new job $id");
        $job = [];
        $job['config'] = $config;
        $job['state']['backoffInterval'] = self::DEFAULT_BACKOFF;
        $job['state']['cfState'] = self::ADDED;
        $this->metadata->put($id, $job);
        return $id;
    }

    public function removeJob($id): void
    {
        if (!$this->has($id)) {
            throw new InvalidArgumentException("Cannot remove job with id $id, id does not exist" . PHP_EOL);
        }
        if ($this->metadata->has($id . '.' . self::PID_KEY)) {
            $this->metadata->put("$id.state.cfState", self::REMOVED);
        }
    }

    public function purgeRemovedJobs(): void
    {
        foreach ($this->metadata as $id => $metadata) {
            if ($metadata['state']['cfState'] == self::REMOVED) {
                if (!empty($metadata['state']['pid'])) {
                    Log::getInstance()->warning("Not purging job $id, it is still running with PID {$metadata['state']['pid']}");
                } else {
                    Log::getInstance()->info("Purging job $id");
                    $this->metadata->remove($id);
                }
            }
        }
    }

    public function slateJobStateUpdates(): void
    {
        foreach ($this->metadata as $id => $job) {
            switch($job['state']['cfState']) {
                case self::UNCHANGED:
                    // Do nothing, restarts are handled in next step by slateScheduledRestarts
                    break;
                case self::REMOVED:
                    // for all REMOVED jobs, slate for shutdown if they have a PID
                    Log::getInstance()->info("job $id is REMOVED");
                    if (!empty($job['state']['pid'])) {
                        Log::getInstance()->info("Slating job $id with pid {$job['state']['pid']} for shutdown");
                        $this->stop->add($id);
                    }
                    break;
                case self::ADDED:
                    // for all ADDED jobs, slate for start
                    Log::getInstance()->info("Job $id is ADDED");
                    $this->start->add($id);
                    break;
                case self::UPDATED:
                    // for all UPDATED jobs, slate for restart if job is running
                    Log::getInstance()->info("Job $id is UPDATED");
                    if (!empty($job['state']['pid'])) {
                        Log::getInstance()->info("Slating job $id with pid {$job['state']['pid']} for restart");
                        $this->restart->add($id);
                    }
                    break;
                default:
                    Log::getInstance()->error("Job $id has invalid cfState {$job['state']['cfState']}");
            }
        }
    }

    public function slateScheduledRestarts(): void
    {
        foreach ($this->metadata as $id => $job) {
            if ($job['state']['cfState'] != self::REMOVED
                && empty($job['state']['pid'])
                && !empty($job['state']['restartAt']) && $job['state']['restartAt'] < time()
            ) {
                Log::getInstance()->info(date('c', time()) . " Job $id restart time reached, slating start");
                $this->start->add($id);
            }
        }
    }

}
