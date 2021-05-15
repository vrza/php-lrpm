<?php

namespace PHPLRPM;

use InvalidArgumentException;

/*
 * idToMetadata = [
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
    const BACKOFF_MULTIPLIER = 2;

    private $idToMetadata = [];
    private $pidToId = [];

    public $start = [];
    public $stop = [];
    public $restart = [];

    public function getAll(): array {
        return $this->idToMetadata;
    }

    public function has(int $id): bool {
        return array_key_exists($id, $this->idToMetadata);
    }

    public function getById(int $id): array {
        return $this->idToMetadata[$id];
    }

    public function getJobsById(array $ids): array {
        $result = [];
        foreach ($ids as $id) {
            if ($this->has($id)) {
                $result[$id] = $this->getById($id);
            }
        }
        return $result;
    }

    public function getJobByPid(int $pid): array {
        if (empty($pid) || !is_int($pid) || $pid < 1) {
            throw new InvalidArgumentException("PID must be a positive integer");
        }
        $id = $this->pidToId[$pid];
        return $this->idToMetadata[$id];
    }

    public function removePid(int $pid): int {
        $id = $this->pidToId[$pid];
        $this->idToMetadata[$id]['state']['pid'] = null;
        unset($this->pidToId[$pid]);
        return $id;
    }

    public function removePids(array $pids): array {
        return array_map(function ($pid) { return $this->removePid($pid); }, $pids);
    }

    public function scheduleRestartsByPIDs(array $pids): array {
        return array_map(function ($pid) { return $this->scheduleRestartByPID($pid); }, $pids);
    }

    public function scheduleRestartByPID(int $pid): int {
        $id = $this->pidToId[$pid];
        $this->scheduleRestart($id);
        return $this->removePid($pid);
    }

    public function scheduleRestarts(array $ids): array {
        return array_map(function ($id) { return $this->scheduleRestart($id); }, $ids);
    }

    public function scheduleRestart(int $id): int {
        if (!$this->has($id)) {
            fwrite(STDERR, 'Will not restart job ' . $id . ' it doesn\'t exist' . PHP_EOL);
            return $id;
        }
        if ($this->idToMetadata[$id]['state']['dbState'] == self::REMOVED) {
            fwrite(STDERR, 'Will not restart job ' . $id . ' it was removed' . PHP_EOL);
            return $id;
        }
        $job = &$this->idToMetadata[$id];
        $now = time();
        if ($now < $job['state']['startedAt'] + self::SHORT_RUN_TIME_SECONDS) {
            $job['state']['restartAt'] = $now + $job['state']['backoffInterval'];
            $job['state']['backoffInterval'] *= self::BACKOFF_MULTIPLIER;
            fwrite(STDOUT, "Job " . $id . " run time was too short, backing off for seconds: " . $job['state']['backoffInterval'] . PHP_EOL);
            var_dump($job['state']);
        } else {
            fwrite(STDOUT, "Job " . $id . " run time was longer than " . self::SHORT_RUN_TIME_SECONDS . ", resetting backoff" . PHP_EOL);
            $job['state']['restartAt'] = $now;
            $job['state']['backoffInterval'] = self::DEFAULT_BACKOFF;
        }
        fwrite(STDOUT, 'id ' . $id . ' scheduled to restart at ' . $job['state']['restartAt'] . PHP_EOL);
        return $id;
    }

    public function updateStartedJob(int $id, int $pid): int {
        if (!$this->has($id)) {
            throw new InvalidArgumentException("Can not update started job, id ". $id . " doesn't exist" . PHP_EOL);
        }
        $this->idToMetadata[$id]['state']['startedAt'] = time();
        $this->idToMetadata[$id]['state']['pid'] = $pid;
        $this->pidToId[$pid] = $id;
        return $id;
    }

    public function getAllPids(): array {
        return array_keys($this->pidToId);
    }

    public function setDbState(int $id, int $dbState) {
        if (!array_key_exists($id, $this->idToMetadata)) {
            throw new InvalidArgumentException("Cant update job with id ". $id . " to configuration state " . $dbState . ", id doesn't exist" . PHP_EOL);
        }
        $this->idToMetadata[$id]['state']['dbState'] = $dbState;
    }

    public function markAsUnchanged(int $id) {
        $this->setDbState($id, WorkerMetadata::UNCHANGED);
    }

    public function updateJob(int $id, array $config): int {
        if (!array_key_exists($id, $this->idToMetadata)) {
            throw new InvalidArgumentException("Cant update job with id ". $id . ", id doesn't exist" . PHP_EOL);
        }
        fwrite(STDOUT, 'Setting fresh config for job ' . $id . PHP_EOL);
        $this->idToMetadata[$id]['config'] = $config;
        $this->idToMetadata[$id]['state']['dbState'] = self::UPDATED;
        $this->idToMetadata[$id]['state']['restartAt'] = time();
        fwrite(STDOUT, 'Resetting backoff for job ' . $id . PHP_EOL);
        $this->idToMetadata[$id]['state']['backoffInterval'] = self::DEFAULT_BACKOFF;
        return $id;
    }

    public function addNewJob(int $id, array $config): int {
        fwrite(STDOUT, 'Adding new job ' . $id . PHP_EOL);
        $this->idToMetadata[$id]['config'] = $config;
        fwrite(STDOUT, 'Resetting backoff for job ' . $id . PHP_EOL);
        $this->idToMetadata[$id]['state']['backoffInterval'] = self::DEFAULT_BACKOFF;
        $this->idToMetadata[$id]['state']['dbState'] = self::ADDED;
        return $id;
    }

    public function removeJob(int $id) {
        if (!array_key_exists($id, $this->idToMetadata)) {
            throw new InvalidArgumentException("Cant remove job with id ". $id . ", id doesn't exist" . PHP_EOL);
        }
        if (!empty($this->idToMetadata['state']['pid'])) {
            $this->idToMetadata[$id]['state']['dbState'] = self::REMOVED;
        }
    }

    public function purgeRemovedJobs() {
        foreach ($this->idToMetadata as $id => $metadata) {
            if ($metadata['state']['dbState'] == self::REMOVED) {
                if (!empty($metadata['state']['pid'])) {
                    fwrite(STDERR, 'Not purging job ' . $id . ' it is still running with PID' . $metadata['state']['pid'] . PHP_EOL);
                } else {
                    fwrite(STDOUT, 'Purging job ' . $id . PHP_EOL);
                    unset($this->idToMetadata[$id]);
                }
            }
        }
    }

    public function updateStateSyncMap() {
        foreach ($this->idToMetadata as $id => $job) {
            fwrite(STDOUT, "State sync map checking job " . $id . PHP_EOL); flush();
            switch($job['state']['dbState']) {
                case self::UNCHANGED:
                    // for all UNCHANGED jobs
                    // - check if they need to be restarted:
                    // - if their PID == null AND their restartAt < now(), slate for start
                    fwrite(STDOUT, "job " . $id . " is UNCHANGED" . PHP_EOL);
                    var_dump($job['state']);
                    if (empty($job['state']['pid']) && !empty($job['state']['restartAt']) && $job['state']['restartAt'] < time()) {
                        fwrite(STDOUT, "job restart time reached, slating start" . PHP_EOL);
                    }
                    break;
                case self::REMOVED:
                    //for all REMOVED jobs, slate for shutdown if they have a PID
                    fwrite(STDOUT, "job " . $id . " is REMOVED" . PHP_EOL);
                    if (!empty($job['state']['pid'])) {
                        fwrite(STDOUT, "slating pid " . $job['state']['pid'] . " for shutdown" . PHP_EOL);
                        $this->stop[$id] = $job;
                    }
                    break;
                case self::ADDED:
                    // for all ADDED jobs, slate for start
                    fwrite(STDOUT, "job " . $id . " is ADDED" . PHP_EOL);
                    var_dump($job['state']);
                    $this->start[$id] = $job;
                    break;
                case self::UPDATED:
                    // for all UPDATED jobs, slate for restart if job is running, slate for start if not running
                    fwrite(STDOUT, "job " . $id . " is UPDATED" . PHP_EOL);
                    var_dump($job['state']);
                    if (!empty($job['state']['pid'])) {
                        fwrite(STDOUT, "slating pid " . $job['state']['pid'] . " for restart" . PHP_EOL);
                        $this->restart[$id] = $job;
                    } else {
                        fwrite(STDOUT, "job is not running, slating start" . PHP_EOL);
                        $this->start[$id] = $job;
                    }
                    break;
                default:
                    fwrite(STDERR, "Job " . $id . " has invalid dbState " . $job['state']['dbState'] . PHP_EOL);
            }
        }
    }

}
