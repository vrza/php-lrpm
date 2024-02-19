<?php

namespace PHPLRPM;

use Exception;
use RuntimeException;
use SimpleIPC\SyMPLib\FileSystemUtils;
use SimpleIPC\SyMPLib\SocketStreamClient;
use SimpleIPC\SyMPLib\UnixDomainSocketAddress;
use PHPLRPM\Serialization\Serializer;

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
    private $serializer;

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

    public function __construct(string $configurationSourceClass, int $configPollIntervalSeconds, Serializer $serializer)
    {
        $this->configurationSource = new $configurationSourceClass();
        $this->configPollIntervalSeconds = $configPollIntervalSeconds;
        $this->serializer = $serializer;
    }

    private function installSignalHandlers(): void
    {
        Log::getInstance()->info('--> Config process installing signal handlers');
        pcntl_signal(SIGHUP, function (int $signo, $_siginfo) {
            Log::getInstance()->notice("--> Config process caught SIGHUP ($signo), will reload configuration");
            $this->timeOfLastConfigPoll = self::CONFIG_POLL_TIME_INIT;
        });
    }

    public function runConfigurationProcessLoop($supervisorPid)
    {
        $this->installSignalHandlers();
        $this->initClient();
        Log::getInstance()->info("--> Signaling parent $supervisorPid that we are up and running");
        posix_kill($supervisorPid, SIGUSR1);
        while (true) {
            $ppid = posix_getppid();
            if ($ppid != $supervisorPid) {
                Log::getInstance()->info('--> Parent PID changed, config process exiting');
                $this->shutdown();
            }
            $haveNewConfig = $this->pollConfigurationSourceForChanges();
            if ($haveNewConfig) {
                try {
                    $this->sendConfigToSupervisor();
                } catch (ConfigurationSendException $e) {
                    Log::getInstance()->error('--> Could not send config to supervisor: ' . $e->getMessage());
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
            Log::getInstance()->info('--> Polling configuration source');
            $this->timeOfLastConfigPoll = $now;
            try {
                $newConfig = $this->configurationSource->loadConfiguration();
                $haveNewConfig = static::isFresher($this->config, $newConfig);
                if ($haveNewConfig) {
                    $this->config = $newConfig;
                }
                if (!$haveNewConfig) Log::getInstance()->info('--> No new config found');
            } catch (Exception $e) {
                Log::getInstance()->error('--> Error loading configuration from source: ' . $e->getMessage());
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
        Log::getInstance()->info('--> Sending new configuration to supervisor');
        $msg = $this->serializer->serialize($this->config);
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
