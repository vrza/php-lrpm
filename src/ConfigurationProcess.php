<?php

namespace PHPLRPM;

use Exception;
use RuntimeException;
use TIPC\FileSystemUtils;
use TIPC\SocketStreamClient;
use TIPC\UnixDomainSocketAddress;

class ConfigurationProcess
{
    public const DEFAULT_CONFIG_POLL_INTERVAL = 30;

    private const CONFIG_POLL_TIME_INIT = 0;

    private $configurationSource;
    private $config = [];
    private $configSocket;
    private $configPollIntervalSeconds;
    private $timeOfLastConfigPoll = self::CONFIG_POLL_TIME_INIT;
    private $client;

    public function findConfigSocket()
    {
        $this->configSocket = FileSystemUtils::findWritableFilePath(
            MessageService::CONFIG_SOCKET_FILE_NAME,
            IPCUtilities::getSocketDirs()
        );
        if (is_null($this->configSocket)) {
            throw new RuntimeException('Config process could not find supervisor config Unix domain socket');
        }
    }

    public function __construct(string $configurationSourceClass, int $configPollIntervalSeconds)
    {
        $this->configurationSource = new $configurationSourceClass();
        $this->configPollIntervalSeconds = $configPollIntervalSeconds;
    }

    private function installSignalHandlers(): void
    {
        fwrite(STDERR, '--> Config process installing signal handlers' . PHP_EOL);
        pcntl_signal(SIGHUP, function (int $signo, $_siginfo) {
            fwrite(STDERR, "--> Config process caught SIGHUP ($signo), will reload configuration" . PHP_EOL);
            $this->timeOfLastConfigPoll = self::CONFIG_POLL_TIME_INIT;
        });
    }

    public function runConfigurationProcessLoop($supervisorPid)
    {
        $this->installSignalHandlers();
        $this->initClient();
        fwrite(STDERR, "--> Signaling parent $supervisorPid that we are up and running" . PHP_EOL);
        posix_kill($supervisorPid, SIGUSR1);
        while (true) {
            $ppid = posix_getppid();
            if ($ppid != $supervisorPid) {
                fwrite(STDERR, '--> Parent PID changed, config process exiting' . PHP_EOL);
                $this->shutdown();
            }
            $haveNewConfig = $this->pollConfigurationSourceForChanges();
            if ($haveNewConfig) {
                try {
                    $this->sendConfigToSupervisor();
                } catch (ConfigurationSendException $e) {
                    fwrite(STDERR, '--> Could not send config to supervisor: ' . $e->getMessage() . PHP_EOL);
                }
            }
            if ($this->configPollIntervalSeconds > 0) {
                sleep($this->configPollIntervalSeconds);
            }
            pcntl_signal_dispatch();
        }
    }

    private function shutdown(): void
    {
        $this->disconnectFromSupervisor();
        exit(ExitCodes::EXIT_PPID_CHANGED);
    }

    private function pollConfigurationSourceForChanges(): bool
    {
        $haveNewConfig = false;
        $now = time();
        if ($this->timeOfLastConfigPoll + $this->configPollIntervalSeconds <= $now) {
            fwrite(STDERR, '--> Polling configuration source' . PHP_EOL);
            $this->timeOfLastConfigPoll = $now;
            try {
                $newConfig = $this->configurationSource->loadConfiguration();
                $haveNewConfig = static::isFresher($this->config, $newConfig);
                if ($haveNewConfig) {
                    $this->config = $newConfig;
                }
                if (!$haveNewConfig) fwrite(STDERR, '--> No new config found' . PHP_EOL);
            } catch (Exception $e) {
                fwrite(STDERR, '--> Error loading configuration from source: ' . $e->getMessage() . PHP_EOL);
            }
        }
        return $haveNewConfig;
    }

    private static function isFresher(array $oldConfig, array $newConfig): bool
    {
        foreach ($newConfig as $newJobId => $newJobConfig) {
            if (array_key_exists($newJobId, $oldConfig)) {
                $oldJobConfig = $oldConfig[$newJobId];
                if ($newJobConfig['mtime'] > $oldJobConfig['mtime']) {
                    return true;
                }
            } else {
                return true;
            }
        }
        foreach ($oldConfig as $oldJobId => $oldJobConfig) {
            if (!array_key_exists($oldJobId, $newConfig)) {
                return true;
            }
        }
        return false;
    }

    private function initClient(): void
    {
        $this->findConfigSocket();
        $recvBufSize = 4 * 1024;
        $this->client = new SocketStreamClient(new UnixDomainSocketAddress($this->configSocket), $recvBufSize);
    }

    private function disconnectFromSupervisor(): void
    {
        if (!is_null($this->client) && $this->client->isConnected()) {
            $this->client->disconnect();
        }
    }

    private function sendConfigToSupervisor(): void
    {
        if (!$this->client->isConnected() && $this->client->connect() === false) {
            throw new RuntimeException("Could not connect to socket {$this->configSocket}");
        }
        fwrite(STDERR, '--> Sending new configuration to supervisor' . PHP_EOL);
        $msg = Serialization::serialize($this->config);
        if ($this->client->sendMessage($msg) === false) {
            $this->client->disconnect();
            throw new ConfigurationSendException("Could not send config over socket {$this->configSocket}");
        }
        if (empty($response = $this->client->receiveMessage())) {
            $this->client->disconnect();
            throw new ConfigurationSendException("Failed to read response from {$this->configSocket}");
        }
        if ($response !== ConfigurationMessageHandler::RESP_OK) {
            throw new ConfigurationSendException("Supervisor at {$this->configSocket} did not acknowledge new config");
        }
    }

}
