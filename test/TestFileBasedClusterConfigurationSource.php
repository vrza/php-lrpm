<?php

namespace PHPLRPM\Test;

use PHPLRPM\Cluster\ShardingConfigurationSource;
use PHPLRPM\Cluster\FileBasedClusterConfigurationProvider;

class TestFileBasedClusterConfigurationSource
{
    private const CONFIG_FILE = __DIR__ . '/lrpm-cluster.conf';

    private $shardingConfigSource;

    public function __construct()
    {
        $this->shardingConfigSource = new ShardingConfigurationSource(
            new MockConfigurationSource(),
            new FileBasedClusterConfigurationProvider(self::CONFIG_FILE)
        );
    }

    public function loadConfiguration(): array
    {
        return $this->shardingConfigSource->loadConfiguration();
    }
}
