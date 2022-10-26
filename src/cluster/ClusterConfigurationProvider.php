<?php

namespace PHPLRPM\Cluster;

interface ClusterConfigurationProvider
{
    public function loadClusterConfiguration(): ClusterConfiguration;
}
