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
    public const DEFAULT_CONFIG_SOCKET_FILE_NAME = 'config';
    public const DEFAULT_CONTROL_SOCKET_FILE_NAME = 'control';

    private static $configSocketFileName = self::DEFAULT_CONFIG_SOCKET_FILE_NAME;
    private static $controlSocketFileName = self::DEFAULT_CONTROL_SOCKET_FILE_NAME;

    private $messageServer;

    public function __construct(MessageHandler $configMessageHandler, MessageHandler $controlMessageHandler)
    {
        $socketsData = [];

        $controlSocketPath = FileSystemUtils::findCreatableFilePath(
            self::$controlSocketFileName,
            IPCUtilities::getSocketDirs()
        );
        if (is_null($controlSocketPath)) {
            Log::getInstance()->notice("==> Control messages disabled");
        } else {
            $socketsData[] = new SocketData(new UnixDomainSocketAddress($controlSocketPath), $controlMessageHandler);
        }

        $configSocketPath = FileSystemUtils::findCreatableFilePath(
            self::$configSocketFileName,
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

    public static function setConfigSocketFileName(string $name): void
    {
        self::$configSocketFileName = $name;
    }

    public static function getConfigSocketFileName(): string
    {
        return self::$configSocketFileName;
    }

    public static function setControlSocketFileName(string $name): void
    {
        self::$controlSocketFileName = $name;
    }

    public static function getControlSocketFileName(): string
    {
        return self::$controlSocketFileName;
    }

}
