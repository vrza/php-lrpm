<?php

namespace PHPLRPM;

class Backoff
{
    private const MULTIPLIER = 2;
    private $min;
    private $max;
    private $current;

    public function __construct(int $minSeconds = 1, int $maxSeconds = 3600)
    {
        $this->min = $minSeconds;
        $this->max = $maxSeconds;
        $this->current = $minSeconds;
    }

    public function getInterval(): int
    {
        return $this->current;
    }

    public function backoff(): void
    {
        fwrite(STDOUT, '[Backoff] Backing off for ' . $this->current . ' seconds' . PHP_EOL);
        sleep($this->current);
        $this->current = ($this->current < $this->max)
            ? min($this->current * self::MULTIPLIER, $this->max)
            : $this->max;
    }

    public function reset(): void
    {
        $this->current = $this->min;
        fwrite(STDOUT, '[Backoff] Reset backoff to ' . $this->current . ' seconds' . PHP_EOL);
    }
}
