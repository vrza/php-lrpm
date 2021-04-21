<?php

namespace PHPLRPM;

class Backoff
{
    const MULTIPLIER = 2;
    private $min;
    private $max;
    private $current = 1;

    public function __construct($minSeconds = 1, $maxSeconds = 3600) {
        $this->min = $minSeconds;
        $this->max = $maxSeconds;
        $this->current = $minSeconds;
    }

    public function getInterval(): int {
        return $this->current;
    }

    public function backoff() {
        fwrite(STDOUT, '[Backoff] Backing off for ' . $this->current . ' seconds' . PHP_EOL);
        sleep($this->current);
        $this->current = ($this->current < $this->max)
            ? min($this->current * self::MULTIPLIER, $this->max)
            : $this->max;
    }

    public function reset() {
        $this->current = $this->min;
        fwrite(STDOUT, '[Backoff] Reset backoff to ' . $this->current . ' seconds' . PHP_EOL);
    }
}
