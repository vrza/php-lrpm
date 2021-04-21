<?php

namespace PHPLRPM;

class MockWorker implements Worker
{
    public function cycle()
    {
        fwrite(STDOUT, "MockWorker tick" . PHP_EOL);
    }
}
