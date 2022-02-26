<?php

namespace PHPLRPM;

use TIPC\MessageHandler;
use TIPC\UnixSocketStreamServer;

class ConfigurationService implements MessageHandler
{
    public const SOCKET_FILE_NAME = 'config';
    public const REQ_POLL_CONFIG_SOURCE = 'config';
    public const RESP_ERROR_CONFIG_SOURCE = 'error_polling_config_source';

    private $messageServer;
    private $configurationSource;

    public function __construct(string $configurationSourceClass)
    {
        $this->initializeMessageServer();
        $this->configurationSource = new $configurationSourceClass();
    }

    public function runConfigurationProcessLoop($supervisorPid)
    {
        $this->startMessageListener();
        fwrite(STDERR, "--> Signaling parent $supervisorPid that we are ready to accept messages" . PHP_EOL);
        posix_kill($supervisorPid, SIGUSR1);
        while (true) {
            $ppid = posix_getppid();
            if ($ppid != $supervisorPid) {
                fwrite(STDERR, "--> Parent PID changed, config process exiting" . PHP_EOL);
                exit(ExitCodes::EXIT_PPID_CHANGED);
            }
            $this->checkMessages(0, 50000);
        }
    }

    public function startMessageListener(): void
    {
        if (!is_null($this->messageServer)) {
            fwrite(STDERR, '--> Starting configuration message listener service' . PHP_EOL);
            $this->messageServer->listen();
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
        $socketPath = UnixSocketStreamServer::findSocketPath(self::SOCKET_FILE_NAME, IPCUtilities::getSocketDirs());
        if (is_null($socketPath)) {
            exit(ExitCodes::EXIT_NO_SOCKET);
        }
        $this->messageServer = new UnixSocketStreamServer($socketPath, $this);
    }

}
