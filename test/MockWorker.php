<?php

namespace PHPLRPM\Test;

use PHPLRPM\Worker;

class MockWorker implements Worker
{
    public function start(): void
    {
        fwrite(STDOUT, "MockWorker initialized" . PHP_EOL);
    }

    public function cycle(): void
    {
        fwrite(STDOUT, "MockWorker cycle" . PHP_EOL);
    }
}
