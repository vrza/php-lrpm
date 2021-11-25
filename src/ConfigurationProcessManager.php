<?php

namespace PHPLRPM;

class ConfigurationProcessManager
{
    private const CONFIG_PROCESS_MAX_BACKOFF_SECONDS = 300;
    private const CONFIG_PROCESS_MAX_RETRIES = 5;
    private const CONFIG_PROCESS_MIN_RUN_TIME_SECONDS = 5;

    private $configProcessId;
    private $configProcessRetries = 0;
    private $configProcessLastStart = 0;

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
    }

    public function shouldRetryStartingConfigProcess(): ?bool
    {
        if ((time() - $this->configProcessLastStart) >= self::CONFIG_PROCESS_MIN_RUN_TIME_SECONDS) {
            $this->configProcessRetries = 0;
        }
        if ($this->configProcessRetries > self::CONFIG_PROCESS_MAX_RETRIES) {
            fwrite(STDERR, '==> Config process failed after ' . self::CONFIG_PROCESS_MAX_RETRIES . ' retries, giving up' . PHP_EOL);
            return null;
        }
        if (is_null($this->configProcessId)) {
            if ($this->configProcessRetries > 0) {
                $backoff = min(2 ** $this->configProcessRetries, self::CONFIG_PROCESS_MAX_BACKOFF_SECONDS);
                fwrite(STDERR, "=> Backing off on config process spawn (retry: " . $this->configProcessRetries . ", seconds: $backoff)" . PHP_EOL);
                sleep($backoff);
            }
            $this->configProcessRetries++;
            $this->configProcessLastStart = time();
            return true;
        }
        return false;
    }

    public function stopConfigurationProcess(): void
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

}
