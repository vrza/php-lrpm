<?php

namespace PHPLRPM\Cluster;

use PHPLRPM\ConfigurationSource;

class ShardingConfigurationSource implements ConfigurationSource
{
    private $confSource;
    private $clusterConf;

    public function __construct(ConfigurationSource $confSource, ClusterConfiguration $clusterConf)
    {
        $this->confSource = $confSource;
        $this->clusterConf = $clusterConf;
    }

    public function loadConfiguration(): array
    {
        $inputConfig = $this->confSource->loadConfiguration();
        $numInstances = $this->clusterConf->numberOfInstances();
        $instance = $this->clusterConf->instanceNumber();
        return self::filterConfig($inputConfig, $numInstances, $instance);
    }

    private static function filterConfig(array $inputConfig, int $numInstances, int $instance): array
    {
        $outputConfig = [];
        foreach ($inputConfig as $key => $value) {
            if (crc32($key) % $numInstances == $instance) {
                $outputConfig[$key] = $value;
            }
        }
        return $outputConfig;
    }
}
