<?php

namespace PHPLRPM\Test;

use PHPLRPM\Cluster\ShardingConfigurationSource;
use PHPLRPM\Cluster\FileBasedClusterConfiguration;
use PHPLRPM\Cluster\ConfigFileReadingException;

class TestClusterConfigurationSource
{
    private const CONFIG_FILE = __DIR__ . '/lrpm-cluster.conf';
    private const EXIT_CONFIG_FILE = 3;

    private $shardingConfigSource;

    public function __construct()
    {
        try {
            $this->shardingConfigSource = new ShardingConfigurationSource(
                new MockConfigurationSource(),
                new FileBasedClusterConfiguration(self::CONFIG_FILE)
            );
        } catch (ConfigFileReadingException $e) {
            fwrite(STDERR, 'Fatal error: ' . $e->getMessage() . PHP_EOL);
            exit(self::EXIT_CONFIG_FILE);
        }
    }

    public function loadConfiguration(): array
    {
        return $this->shardingConfigSource->loadConfiguration();
    }
}
