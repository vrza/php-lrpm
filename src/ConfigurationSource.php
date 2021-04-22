<?php


namespace PHPLRPM;


interface ConfigurationSource
{
    public function loadConfiguration(): array;
}
