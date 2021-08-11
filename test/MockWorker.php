<?php

namespace PHPLRPM\Test;

use PHPLRPM\Worker;

class MockWorker implements Worker
{
    public function start(array $config): void
    {
        fwrite(STDOUT, "MockWorker initialized" . PHP_EOL);
    }

    public function cycle(): void
    {
        fwrite(STDOUT, "MockWorker cycle" . PHP_EOL);
        sleep(2);
    }
}
