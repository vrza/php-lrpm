<?php

namespace PHPLRPM;

interface Worker
{
    public function start(): void;
    public function cycle(): void;
}
