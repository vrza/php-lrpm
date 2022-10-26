<?php

namespace PHPLRPM\Cluster;

use PHPLRPM\ConfigurationSource;

class ShardingConfigurationSource implements ConfigurationSource
{
    private $confSource;
    private $clusterConfProvider;

    public function __construct(ConfigurationSource $confSource, ClusterConfigurationProvider $clusterConfProvider)
    {
        $this->confSource = $confSource;
        $this->clusterConfProvider = $clusterConfProvider;
    }

    public function loadConfiguration(): array
    {
        $inputConfig = $this->confSource->loadConfiguration();
        $clusterConfig = $this->clusterConfProvider->loadClusterConfiguration();
        return self::filterConfig($inputConfig, $clusterConfig);
    }

    private static function filterConfig(array $inputConfig, ClusterConfiguration $clusterConf): array
    {
        $numberOfInstances = $clusterConf->getNumberOfInstances();
        $instanceNumber = $clusterConf->getInstanceNumber();
        $outputConfig = [];

        foreach ($inputConfig as $key => $value) {
            if (crc32($key) % $numberOfInstances === $instanceNumber) {
                $outputConfig[$key] = $value;
            }
        }

        return $outputConfig;
    }
}
