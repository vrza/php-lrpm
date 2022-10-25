<?php

namespace PHPLRPM\Test;

use PHPLRPM\Cluster\ShardingConfigurationSource;
use PHPLRPM\Cluster\FileBasedClusterConfiguration;

class TestFileBasedClusterConfigurationSource
{
    private const CONFIG_FILE = __DIR__ . '/lrpm-cluster.conf';

    private $shardingConfigSource;

    public function __construct()
    {
        $this->shardingConfigSource = new ShardingConfigurationSource(
            new MockConfigurationSource(),
            new FileBasedClusterConfiguration(self::CONFIG_FILE)
        );
    }

    public function loadConfiguration(): array
    {
        return $this->shardingConfigSource->loadConfiguration();
    }
}
