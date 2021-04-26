<?php

namespace PHPLRPM;

class MockWorker implements Worker
{
    public function start()
    {
        fwrite(STDOUT, "MockWorker initialized" . PHP_EOL);
    }

    public function cycle()
    {
        fwrite(STDOUT, "MockWorker cycle" . PHP_EOL);
    }
}
