<?php

namespace PHPLRPM;

use TIPC\MessageHandler;
use TIPC\UnixSocketStreamServer;

class ConfigurationService implements MessageHandler
{
    private const EXIT_NO_SOCKET = 69;

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
            fwrite(STDOUT, '==> Starting configuration message listener service' . PHP_EOL);
            $this->messageServer->listen();
        }
    }

    public function stopMessageListener(): void
    {
        if (!is_null($this->messageServer)) {
            $this->messageServer->close();
        }
    }

    public function checkMessages(): void
    {
        if (!is_null($this->messageServer)) {
            $this->messageServer->checkMessages();
        }
    }

    public function handleMessage(string $msg): string
    {
        $config = $this->configurationSource->loadConfiguration();
        return Serialization::serialize($config);
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
