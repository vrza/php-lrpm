<?php

namespace PHPLRPM\Cluster;

interface ClusterConfiguration
{
    public function getInstanceNumber(): int;
    public function getNumberOfInstances(): int;
    public function reload();
}
