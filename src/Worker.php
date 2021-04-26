<?php

namespace PHPLRPM;

interface Worker
{
    public function start();
    public function cycle();
}
