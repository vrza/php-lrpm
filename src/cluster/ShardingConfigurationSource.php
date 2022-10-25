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
        $this->clusterConf->reload();
        $numInstances = $this->clusterConf->getNumberOfInstances();
        $instance = $this->clusterConf->getInstanceNumber();
        if (!self::isValidClusterConfig($numInstances, $instance)) {
            $errorMsg = 'Invalid cluster config: ' .
                'number of instances = ' . strval($numInstances) .
                ', instance number = ' . strval($instance);
            throw new ClusterConfigurationValidationException($errorMsg);
        }
        return self::filterConfig($inputConfig, $numInstances, $instance);
    }

    private static function isValidClusterConfig(int $numInstances, int $instance)
    {
        return is_int($numInstances)
            && $numInstances > 0
            && is_int($instance)
            && $instance >= 0
            && $instance < $numInstances;
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
