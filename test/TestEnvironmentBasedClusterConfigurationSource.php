<?php

namespace PHPLRPM\Test;

use PHPLRPM\Cluster\EnvironmentBasedClusterConfiguration;
use PHPLRPM\Cluster\ShardingConfigurationSource;

class TestEnvironmentBasedClusterConfigurationSource
{
    private $shardingConfigSource;

    public function __construct()
    {
        $this->shardingConfigSource = new ShardingConfigurationSource(
            new MockConfigurationSource(),
            new EnvironmentBasedClusterConfiguration()
        );
    }

    public function loadConfiguration(): array
    {
        return $this->shardingConfigSource->loadConfiguration();
    }
}
