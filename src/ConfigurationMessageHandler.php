<?php

namespace PHPLRPM;

use TIPC\MessageHandler;

class ConfigurationMessageHandler implements MessageHandler
{
    public const RESP_OK = 'ok';

    private $processManager;

    public function __construct(ProcessManager $processManager)
    {
        $this->processManager = $processManager;
    }

    public function handleMessage(string $msg): string
    {
        $config = Serialization::deserialize($msg);
        $this->processManager->setNewConfig($config);
        return self::RESP_OK;
    }

}
