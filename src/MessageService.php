<?php

namespace PHPLRPM;

use RuntimeException;
use TIPC\FileSystemUtils;
use TIPC\MessageHandler;
use TIPC\SocketData;
use TIPC\SocketStreamsServer;
use TIPC\UnixDomainSocketAddress;

class MessageService
{
    private $messageServer;

    public function __construct(MessageHandler $configMessageHandler, MessageHandler $controlMessageHandler)
    {
        $socketsData = [];
        $controlSocketPath = FileSystemUtils::findCreatableFilePath('control', IPCUtilities::getSocketDirs());
        if (is_null($controlSocketPath)) {
            fwrite(STDERR, "==> Control messages disabled" . PHP_EOL);
        } else {
            $socketsData[] = new SocketData(new UnixDomainSocketAddress($controlSocketPath), $controlMessageHandler);
        }
        $configSocketPath = FileSystemUtils::findCreatableFilePath('config', IPCUtilities::getSocketDirs());
        if (is_null($configSocketPath)) {
            throw new RuntimeException("Failed to find a config socket to listen on");
        }
        $socketsData[] = new SocketData(new UnixDomainSocketAddress($configSocketPath), $configMessageHandler);
        $this->messageServer = new SocketStreamsServer($socketsData);
    }

    public function destroyMessageServer(): void
    {
        $this->stopMessageListener();
        $this->messageServer = null;
    }

    public function startMessageListener(): void
    {
        if (!is_null($this->messageServer)) {
            fwrite(STDOUT, '==> Starting message listener service' . PHP_EOL);
            $this->messageServer->listen();
        }
    }

    public function stopMessageListener(): void
    {
        if (!is_null($this->messageServer)) {
            $this->messageServer->closeAll();
        }
    }

    public function checkMessages(int $timeoutSeconds = 0, int $timeoutMicroseconds = 0): void
    {
        if (!is_null($this->messageServer)) {
            $this->messageServer->checkMessages($timeoutSeconds, $timeoutMicroseconds);
        }
    }

}
