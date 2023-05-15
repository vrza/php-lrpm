<?php

namespace PHPLRPM;

use TIPC\MessageHandler;
use PHPLRPM\Serialization\Serializer;

class ConfigurationMessageHandler implements MessageHandler
{
    public const RESP_OK = 'ok';

    private $processManager;
    private $serializer;

    public function __construct(ProcessManager $processManager, Serializer $serializer)
    {
        $this->processManager = $processManager;
        $this->serializer = $serializer;
    }

    public function handleMessage(string $msg): string
    {
        $config = $this->serializer::deserialize($msg);
        $this->processManager->setNewConfig($config);
        return self::RESP_OK;
    }

}
