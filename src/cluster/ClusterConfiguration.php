<?php

namespace PHPLRPM\Cluster;

interface ClusterConfiguration
{
    public function instanceNumber(): int;
    public function numberOfInstances(): int;
}
