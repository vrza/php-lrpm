<?php

namespace PHPLRPM;

use RuntimeException;
use TIPC\UnixSocketStreamClient;

class ConfigurationProcessManager
{
    private const EXIT_PPID_CHANGED = 2;
    private const CONFIG_PROCESS_MAX_BACKOFF_SECONDS = 300;
    private const CONFIG_PROCESS_MAX_RETRIES = 5;
    private const CONFIG_PROCESS_MIN_RUN_TIME_SECONDS = 5;

    private $shouldRun = true;
    private $configurationSourceClass;
    private $configSocket;
    private $configProcessId;
    private $configProcessRetries = 0;
    private $configProcessLastStart = 0;
    private $supervisorSignalHandlers;
    private $lastSigUsr1Info = [];

    public function __construct(string $configurationSourceClass, $signalHandlers)
    {
        $this->configurationSourceClass = $configurationSourceClass;
        $this->supervisorSignalHandlers = $signalHandlers;
    }

    public function shutdown()
    {
        $this->shouldRun = false;
    }

    public function getPID(): ?int
    {
        return $this->configProcessId;
    }

    public function setLastSigUsr1Info($siginfo)
    {
        $this->lastSigUsr1Info = $siginfo;
    }

    private function getLastSigUsr1Pid(): int
    {
        return $this->lastSigUsr1Info['pid'] ?? 0;
    }

    public function retryStartingConfigProcess()
    {
        if ((time() - $this->configProcessLastStart) >= self::CONFIG_PROCESS_MIN_RUN_TIME_SECONDS) {
            $this->configProcessRetries = 0;
        }
        if ($this->configProcessRetries > self::CONFIG_PROCESS_MAX_RETRIES) {
            fwrite(STDERR, '==> Config process failed after ' . self::CONFIG_PROCESS_MAX_RETRIES . ' retries, giving up' . PHP_EOL);
            return false;
        }
        if (is_null($this->configProcessId)) {
            if ($this->configProcessRetries > 0) {
                $backoff = min(2 ** $this->configProcessRetries, self::CONFIG_PROCESS_MAX_BACKOFF_SECONDS);
                fwrite(STDERR, "=> Backing off on config process spawn (retry: " . $this->configProcessRetries . ", seconds: $backoff)" . PHP_EOL);
                sleep($backoff);
            }
            $this->configProcessRetries++;
            $this->configProcessLastStart = time();
            $this->startConfigurationProcess();
        }
        return $this->configProcessRetries;
    }

    public function startConfigurationProcess(): void
    {
        $supervisorPid = getmypid();
        $signals = array_keys($this->supervisorSignalHandlers);
        flush();
        pcntl_sigprocmask(SIG_BLOCK, $signals);
        $pid = pcntl_fork();
        if ($pid === 0) { // child process
            foreach ($this->supervisorSignalHandlers as $signal => $_handler) {
                pcntl_signal($signal, SIG_DFL);
            }
            $configPid = getmypid();
            fwrite(STDERR, "--> Config process with PID $configPid running" . PHP_EOL);
            ProcessManager::setChildProcessTitle('config');
            $configurationService = new ConfigurationService($this->configurationSourceClass);
            $configurationService->startMessageListener();
            fwrite(STDERR, "--> Signaling parent $supervisorPid that we are ready to accept messages" . PHP_EOL);
            posix_kill($supervisorPid, SIGUSR1);
            while (true) {
                $ppid = posix_getppid();
                if ($ppid != $supervisorPid) {
                    fwrite(STDERR, "--> Parent PID changed, config process exiting" . PHP_EOL);
                    exit(self::EXIT_PPID_CHANGED);
                }
                $configurationService->checkMessages(0, 50000);
            }
        } elseif ($pid > 0) { // parent process
            pcntl_sigprocmask(SIG_UNBLOCK, $signals);
            $this->configProcessId = $pid;
            fwrite(STDERR, "==> Forked config process with PID: $pid" . PHP_EOL);
            fwrite(STDERR, "==> Waiting for config service to let us know it's ready" . PHP_EOL);
            $remaining = 60;
            while ($this->getLastSigUsr1Pid() !== $pid && $this->shouldRun && $remaining > 0) {
                $remaining = sleep($remaining);
                pcntl_signal_dispatch();
            }
            if (!$this->shouldRun) {
                return;
            }
            if ($remaining == 0) {
                throw new RuntimeException("Config process did not send readiness notification");
            }
            $this->configSocket = IPCUtilities::clientFindUnixSocket('config', IPCUtilities::getSocketDirs());
            if (is_null($this->configSocket)) {
                throw new RuntimeException("Supervisor could not find config process Unix domain socket");
            }
        } else {
            throw new RuntimeException("Error forking config process: $pid");
        }
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

    public function handleTerminatedConfigProcess(): void
    {
        $this->configProcessId = null;
    }

    /**
     * Creates a configuration client and contacts the configuration
     * process with a request to poll for latest configuration.
     *
     * @return array containing latest configuration
     * @throws ConfigurationPollException
     */
    public function pollConfiguration(): array
    {
        $recvBufSize = 8 * 1024 * 1024;
        $client = new UnixSocketStreamClient($this->configSocket, $recvBufSize);
        if ($client->connect() === false) {
            throw new ConfigurationPollException("Could not connect to socket {$this->configSocket}");
        }
        if ($client->sendMessage(ConfigurationService::REQ_POLL_CONFIG_SOURCE) === false) {
            $client->disconnect();
            throw new ConfigurationPollException("Could not send config query message over socket {$this->configSocket}");
        }
        $signals = array_keys($this->supervisorSignalHandlers);
        pcntl_sigprocmask(SIG_BLOCK, $signals);
        if (empty($response = $client->receiveMessage())) {
            pcntl_sigprocmask(SIG_UNBLOCK, $signals);
            $client->disconnect();
            throw new ConfigurationPollException("Failed to read config response from {$this->configSocket}");
        }
        pcntl_sigprocmask(SIG_UNBLOCK, $signals);
        $client->disconnect();
        if ($response === ConfigurationService::RESP_ERROR_CONFIG_SOURCE) {
            throw new ConfigurationPollException("Config process at {$this->configSocket} responded with an error message");
        }
        return Serialization::deserialize($response);
    }
}
