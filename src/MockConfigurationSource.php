<?php


namespace PHPLRPM;


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
                'workerClass' => '\PHPLRPM\MockWorker',
                'mtime' => $this->mtime
            ],
            42 => [
                'name' => 'forty-two',
                'workerClass' => '\PHPLRPM\MockWorker',
                'mtime' => time()
            ],
            33 => [
                'name' => 'thirty-three',
                'workerClass' => '\PHPLRPM\MockWorker',
                'mtime' => $this->mtime
            ]
        ];
        return $configurationItems;
    }
}
