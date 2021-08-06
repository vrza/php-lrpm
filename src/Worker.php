<?php

namespace PHPLRPM;

interface Worker
{
    public function start(array $config): void;
    public function cycle(): void;
}
