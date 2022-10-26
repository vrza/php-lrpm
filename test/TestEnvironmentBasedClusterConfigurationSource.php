<?php

namespace PHPLRPM\Test;

use PHPLRPM\Cluster\EnvironmentBasedClusterConfigurationProvider;
use PHPLRPM\Cluster\ShardingConfigurationSource;

class TestEnvironmentBasedClusterConfigurationSource
{
    private $shardingConfigSource;

    public function __construct()
    {
        $this->shardingConfigSource = new ShardingConfigurationSource(
            new MockConfigurationSource(),
            new EnvironmentBasedClusterConfigurationProvider()
        );
    }

    public function loadConfiguration(): array
    {
        return $this->shardingConfigSource->loadConfiguration();
    }
}
