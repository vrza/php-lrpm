<?php

namespace PHPLRPM;

use RuntimeException;
use SimpleIPC\SyMPLib\FileSystemUtils;
use SimpleIPC\SyMPLib\MessageHandler;
use SimpleIPC\SyMPLib\SocketData;
use SimpleIPC\SyMPLib\SocketStreamsServer;
use SimpleIPC\SyMPLib\UnixDomainSocketAddress;

class MessageService
{
    public const CONFIG_SOCKET_FILE_NAME = 'config';
    public const CONTROL_SOCKET_FILE_NAME = 'control';

    private $messageServer;

    public function __construct(MessageHandler $configMessageHandler, MessageHandler $controlMessageHandler)
    {
        $socketsData = [];

        $controlSocketPath = FileSystemUtils::findCreatableFilePath(
            self::CONTROL_SOCKET_FILE_NAME,
            IPCUtilities::getSocketDirs()
        );
        if (is_null($controlSocketPath)) {
            Log::getInstance()->notice("==> Control messages disabled");
        } else {
            $socketsData[] = new SocketData(new UnixDomainSocketAddress($controlSocketPath), $controlMessageHandler);
        }

        $configSocketPath = FileSystemUtils::findCreatableFilePath(
            self::CONFIG_SOCKET_FILE_NAME,
            IPCUtilities::getSocketDirs()
        );
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
            Log::getInstance()->info('==> Starting message listener service');
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
