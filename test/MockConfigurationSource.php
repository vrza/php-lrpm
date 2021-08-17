<?php

namespace PHPLRPM\Test;

use PHPLRPM\ConfigurationSource;

class MockConfigurationSource implements ConfigurationSource
{
    private $mtime;

    public function __construct()
    {
        $this->mtime = time();
    }

    public function loadConfiguration(): array
    {
        $configurationItems = [
            23 => [
                'name' => 'twenty-three',
                'workerClass' => '\PHPLRPM\Test\MockWorker',
                'mtime' => $this->mtime,
                'workerConfig' => []
            ],
            42 => [
                'name' => 'forty-two',
                'workerClass' => '\PHPLRPM\Test\MockWorker',
                'mtime' => time(),
                'workerConfig' => []
            ],
            33 => [
                'name' => 'thirty-three',
                'workerClass' => '\PHPLRPM\Test\MockWorker',
                'mtime' => $this->mtime,
                'workerConfig' => []
            ]
        ];
        return $configurationItems;
    }
}
