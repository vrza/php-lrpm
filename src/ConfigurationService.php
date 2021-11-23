<?php

namespace PHPLRPM;

use TIPC\MessageHandler;
use TIPC\UnixSocketStreamServer;

class ConfigurationService implements MessageHandler
{
    private const EXIT_NO_SOCKET = 69;

    public const REQ_POLL_CONFIG_SOURCE = 'config';
    public const RESP_ERROR_CONFIG_SOURCE = 'error_polling_config_source';

    private $messageServer;
    private $configurationSource;

    public function __construct(string $configurationSourceClass)
    {
        $this->initializeMessageServer();
        $this->configurationSource = new $configurationSourceClass();
    }

    public function startMessageListener(): void
    {
        if (!is_null($this->messageServer)) {
            fwrite(STDERR, '--> Starting configuration message listener service' . PHP_EOL);
            $this->messageServer->listen();
        }
    }

    public function stopMessageListener(): void
    {
        if (!is_null($this->messageServer)) {
            $this->messageServer->close();
        }
    }

    public function checkMessages($timeoutSeconds = 0, $timeoutMicroseconds = 0): void
    {
        if (!is_null($this->messageServer)) {
            $this->messageServer->checkMessages($timeoutSeconds, $timeoutMicroseconds);
        }
    }

    public function handleMessage(string $msg): string
    {
        $config = self::RESP_ERROR_CONFIG_SOURCE;
        try {
            $config = $this->configurationSource->loadConfiguration();
        } catch (Exception $e) {
            fwrite(STDERR, '--> Error loading configuration from source: ' . $e->getMessage() . PHP_EOL);
        }
        return $config === self::RESP_ERROR_CONFIG_SOURCE ? $config : Serialization::serialize($config);
    }

    private function initializeMessageServer()
    {
        $socketPath = IPCUtilities::serverFindUnixSocket('config', IPCUtilities::getSocketDirs());
        if (is_null($socketPath)) {
            exit(self::EXIT_NO_SOCKET);
        }
        $this->messageServer = new UnixSocketStreamServer($socketPath, $this);
    }

}
