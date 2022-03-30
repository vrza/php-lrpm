<?php

namespace PHPLRPM;

class ConfigurationProcessManager
{
    private const CONFIG_PROCESS_MAX_BACKOFF_SECONDS = 300;
    private const CONFIG_PROCESS_MAX_RETRIES = 5;
    private const CONFIG_PROCESS_MIN_RUN_TIME_SECONDS = 40;
    private const CONFIG_PROCESS_TERM_TIMEOUT_SECONDS = 5;

    private $configProcessId;
    private $configProcessRetries = 0;
    private $configProcessLastStart = 0;
    private $configProcessRestartAt = 0;

    public function getPID(): ?int
    {
        return $this->configProcessId;
    }

    public function setPID($pid): void
    {
        $this->configProcessId = $pid;
    }

    public function handleTerminatedConfigProcess(): void
    {
        $this->configProcessId = null;
        $this->scheduleRestartWithBackoff();
    }

    private function scheduleRestartWithBackoff()
    {
        $now = time();
        if ($now - $this->configProcessLastStart >= self::CONFIG_PROCESS_MIN_RUN_TIME_SECONDS) {
            $this->configProcessRetries = 0;
        } else {
            $backoff = min(2 ** $this->configProcessRetries, self::CONFIG_PROCESS_MAX_BACKOFF_SECONDS);
            fwrite(STDERR, "==> Backing off on config process spawn (retry: {$this->configProcessRetries}, seconds: $backoff)" . PHP_EOL);
            $this->configProcessRestartAt = $now + $backoff;
            $this->configProcessRetries++;
        }
    }

    public function shouldRetryStartingConfigProcess(): ?bool
    {
        if (!is_null($this->configProcessId)) {
            return false;
        }
        if ($this->configProcessRetries > self::CONFIG_PROCESS_MAX_RETRIES) {
            fwrite(STDERR, '==> Config process failed after ' . self::CONFIG_PROCESS_MAX_RETRIES . ' retries, giving up' . PHP_EOL);
            return null;
        }
        $now = time();
        if ($this->configProcessRestartAt < $now) {
            $this->configProcessLastStart = $now;
            return true;
        }
        return false;
    }

    public function stopConfigurationProcess(): void
    {
        if (is_null($this->configProcessId)) {
            return;
        }
        posix_kill($this->configProcessId, SIGTERM);
        $remaining = self::CONFIG_PROCESS_TERM_TIMEOUT_SECONDS;
        while (!is_null($this->configProcessId) && $remaining > 0) {
            $remaining = sleep($remaining);
            pcntl_signal_dispatch();
        }
        if (!is_null($this->configProcessId)) {
            posix_kill($this->configProcessId, SIGKILL);
        }
    }

    public function sendSignalToConfigProcess(int $signal): void
    {
        fwrite(STDERR, "==> Sending signal $signal to config process with pid {$this->configProcessId}" . PHP_EOL);
        posix_kill($this->configProcessId, $signal);
    }

}
