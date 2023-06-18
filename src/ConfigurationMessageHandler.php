<?php

namespace PHPLRPM;

use TIPC\MessageHandler;
use PHPLRPM\Serialization\Serializer;
use PHPLRPM\Serialization\SerializationException;

class ConfigurationMessageHandler implements MessageHandler
{
    public const RESP_OK = 'ok';
    public const RESP_EDESERIALIZE = 'cannot deserialize payload';

    private $processManager;
    private $serializer;

    public function __construct(ProcessManager $processManager, Serializer $serializer)
    {
        $this->processManager = $processManager;
        $this->serializer = $serializer;
    }

    public function handleMessage(string $msg): string
    {
        try {
            $config = $this->serializer->deserialize($msg);
            $this->processManager->setNewConfig($config);
            return self::RESP_OK;
        } catch (SerializationException $e) {
            fwrite(STDERR, 'Error deserializing configuration: ' . $e->getMessage() . PHP_EOL);
            return self::RESP_EDESERIALIZE;
        }
    }

}
