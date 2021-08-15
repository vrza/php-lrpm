<?php

namespace PHPLRPM;

use InvalidArgumentException;
use CardinalCollections\ArrayWithSecondaryKeys\ArrayWithSecondaryKeys;
use CardinalCollections\Mutable\Set;

/*
 * (int) => [
 *     'config' => []; // user defined
 *     'state' => [
 *         'pid' => (int)
 *         'restartAt' => (int)
 *         'backoffInterval' => (int) //1, 2, 4, 8 ...
 *         'dbState',  // ADDED | REMOVED | UNCHANGED since last poll
 *         'lastExitCode'
 *     ]
 * ]
 */
class WorkerMetadata {
    const UNCHANGED = 0;
    const ADDED = 1;
    const REMOVED = 2;
    const UPDATED = 3;

    const SHORT_RUN_TIME_SECONDS = 5;

    const DEFAULT_BACKOFF = 1;
    const MAX_BACKOFF = 60 * 60 * 6;
    const BACKOFF_MULTIPLIER = 2;

    const PID_KEY = 'state.pid';

    private $metadata;

    public $start;
    public $stop;
    public $restart;

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

    public function getById($id): array
    {
        return $this->metadata->get($id);
    }

    public function getJobsById(iterable $ids): array
    {
        $result = [];
        foreach ($ids as $id) {
            if ($this->has($id)) {
                $result[$id] = $this->getById($id);
            }
        }
        return $result;
    }

    public function getJobByPid(int $pid): array
    {
        if (empty($pid) || !is_int($pid) || $pid < 1) {
            throw new InvalidArgumentException("PID must be a positive integer");
        }
        return $this->metadata->getByIndex(self::PID_KEY, $pid);
    }

    public function removePid(int $pid)
    {
        return $this->metadata->updateSecondaryKey(self::PID_KEY, $pid, null);
    }

    public function removePids(array $pids): array
    {
        return array_map(function ($pid) { return $this->removePid($pid); }, $pids);
    }

    public function scheduleRestartsByPIDs(array $pids): array
    {
        return array_map(function ($pid) { return $this->scheduleRestartByPID($pid); }, $pids);
    }

    public function scheduleRestartByPID(int $pid)
    {
        $id = $this->metadata->getPrimaryKeyByIndex(self::PID_KEY, $pid);
        $this->scheduleRestart($id);
        return $this->removePid($pid);
    }

    public function scheduleRestarts(array $ids): array
    {
        return array_map(function ($id) { return $this->scheduleRestart($id); }, $ids);
    }

    public function scheduleRestart($id)
    {
        if (!$this->has($id)) {
            fwrite(STDERR, "Will not restart job $id, it does not exist" . PHP_EOL);
            return $id;
        }
        if ($this->metadata->get("$id.state.dbState") == self::REMOVED) {
            fwrite(STDERR, "Will not restart job $id, it was removed" . PHP_EOL);
            return $id;
        }
        $job = $this->metadata->get($id);
        $now = time();
        if ($now < $job['state']['startedAt'] + self::SHORT_RUN_TIME_SECONDS) {
            $job['state']['restartAt'] = $now + $job['state']['backoffInterval'];
            $job['state']['backoffInterval'] *= self::BACKOFF_MULTIPLIER;
            if ($job['state']['backoffInterval'] > self::MAX_BACKOFF) {
                $job['state']['backoffInterval'] = self::MAX_BACKOFF;
            }
            fwrite(STDOUT, "Job $id run time was too short, backing off for seconds: " . $job['state']['backoffInterval'] . PHP_EOL);
            var_dump($job['state']);
        } else {
            fwrite(STDOUT, "Job $id run time was longer than " . self::SHORT_RUN_TIME_SECONDS . ", resetting backoff" . PHP_EOL);
            $job['state']['restartAt'] = $now;
            $job['state']['backoffInterval'] = self::DEFAULT_BACKOFF;
        }
        fwrite(STDOUT, date('c', time()) . " Job $id scheduled to restart at " . date('c', $job['state']['restartAt']) . PHP_EOL);
        $this->metadata->put($id, $job);
        return $id;
    }

    public function scheduleImmediateRestart($id): string
    {
        if (!$this->has($id)) {
            return("Will not restart job $id, it does not exist");
        }
        if ($this->metadata->get("$id.state.dbState") == self::REMOVED) {
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
        if (!$this->has($id)) {
            throw new InvalidArgumentException("Cannot update started job, id $id does not exist" . PHP_EOL);
        }
        $metadata = $this->metadata->get($id);
        $metadata['state']['startedAt'] = time();
        $metadata['state']['pid'] = $pid;
        $this->metadata->put($id, $metadata);
        return $id;
    }

    public function getAllPids(): array
    {
        return $this->metadata->secondaryKeys(self::PID_KEY);
    }

    public function setDbState($id, int $dbState): void
    {
        if (!$this->has($id)) {
            throw new InvalidArgumentException("Cannot update job with id $id to configuration state $dbState, id does not exist" . PHP_EOL);
        }
        $this->metadata->put("$id.state.dbState", $dbState);
    }

    public function markAsUnchanged($id)
    {
        $this->setDbState($id, WorkerMetadata::UNCHANGED);
    }

    public function updateJob($id, array $config)
    {
        if (!$this->has($id)) {
            throw new InvalidArgumentException("Cannot update job with id $id, id does not exist" . PHP_EOL);
        }
        fwrite(STDOUT, "Setting fresh config for job $id" . PHP_EOL);
        $job = $this->metadata->get($id);
        $job['config'] = $config;
        $job['state']['dbState'] = self::UPDATED;
        $job['state']['restartAt'] = time();
        fwrite(STDOUT, "Resetting backoff for job $id" . PHP_EOL);
        $job['state']['backoffInterval'] = self::DEFAULT_BACKOFF;
        $this->metadata->put($id, $job);
        return $id;
    }

    public function addNewJob($id, array $config)
    {
        fwrite(STDOUT, "Adding new job $id" . PHP_EOL);
        fwrite(STDOUT, "Resetting backoff for job $id" . PHP_EOL);
        $metadata = $this->has($id) ? $this->metadata->get($id) : [];
        $metadata['config'] = $config;
        $metadata['state']['backoffInterval'] = self::DEFAULT_BACKOFF;
        $metadata['state']['dbState'] = self::ADDED;
        $this->metadata->put($id, $metadata);
        return $id;
    }

    public function removeJob($id): void
    {
        if (!$this->has($id)) {
            throw new InvalidArgumentException("Cannot remove job with id $id, id does not exist" . PHP_EOL);
        }
        if ($this->metadata->has($id . '.' . self::PID_KEY)) {
            $this->metadata->put("$id.state.dbState", self::REMOVED);
        }
    }

    public function purgeRemovedJobs(): void
    {
        foreach ($this->metadata as $id => $metadata) {
            if ($metadata['state']['dbState'] == self::REMOVED) {
                if (!empty($metadata['state']['pid'])) {
                    fwrite(STDERR, "Not purging job $id, it is still running with PID" . $metadata['state']['pid'] . PHP_EOL);
                } else {
                    fwrite(STDOUT, "Purging job $id" . PHP_EOL);
                    $this->metadata->remove($id);
                }
            }
        }
    }

    public function slateJobStateUpdates(): void
    {
        foreach ($this->metadata as $id => $job) {
            //fwrite(STDOUT, "State sync map checking job $id" . PHP_EOL); flush();

            switch($job['state']['dbState']) {
                case self::UNCHANGED:
                    // for all UNCHANGED jobs
                    // - check if they need to be restarted:
                    // - if their PID == null AND their restartAt < now(), slate for start
                    //fwrite(STDOUT, "Job $id is UNCHANGED" . PHP_EOL);
                    //var_dump($job['state']);
                    if (empty($job['state']['pid']) && !empty($job['state']['restartAt']) && $job['state']['restartAt'] < time()) {
                        fwrite(STDOUT, date('c', time()) . " Job $id restart time reached, slating start" . PHP_EOL);
                        $this->start->add($id);
                    }
                    break;
                case self::REMOVED:
                    //for all REMOVED jobs, slate for shutdown if they have a PID
                    fwrite(STDOUT, "job $id is REMOVED" . PHP_EOL);
                    if (!empty($job['state']['pid'])) {
                        fwrite(STDOUT, "Slating job " . $id . " with pid " . $job['state']['pid'] . " for shutdown" . PHP_EOL);
                        $this->stop->add($id);
                    }
                    break;
                case self::ADDED:
                    // for all ADDED jobs, slate for start
                    fwrite(STDOUT, "Job $id is ADDED" . PHP_EOL);
                    //var_dump($job['state']);
                    $this->start->add($id);
                    break;
                case self::UPDATED:
                    // for all UPDATED jobs, slate for restart if job is running, slate for start if not running
                    fwrite(STDOUT, "Job $id is UPDATED" . PHP_EOL);
                    //var_dump($job['state']);
                    if (!empty($job['state']['pid'])) {
                        fwrite(STDOUT, "Slating job " . $id . " with pid " . $job['state']['pid'] . " for restart" . PHP_EOL);
                        $this->restart->add($id);
                    } else {
                        fwrite(STDOUT, "Job $id is not running, slating start" . PHP_EOL);
                        $this->start->add($id);
                    }
                    break;
                default:
                    fwrite(STDERR, "Job $id has invalid dbState " . $job['state']['dbState'] . PHP_EOL);
            }
        }
    }

}
