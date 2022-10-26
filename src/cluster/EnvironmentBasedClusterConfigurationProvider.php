<?php

namespace PHPLRPM\Cluster;

class EnvironmentBasedClusterConfigurationProvider implements ClusterConfigurationProvider
{
    const DEFAULT_VAR_NUM_OF_INSTANCES = 'PHP_LRPM_NUMBER_OF_INSTANCES';
    const DEFAULT_VAR_INSTANCE_NUM = 'PHP_LRPM_INSTANCE_NUMBER';

    private $varNumOfInstances;
    private $varInstanceNum;

    public function __construct(
        string $varNumOfInstances = self::DEFAULT_VAR_NUM_OF_INSTANCES,
        string $varInstanceNum = self::DEFAULT_VAR_INSTANCE_NUM
    ) {
        $this->varNumOfInstances = $varNumOfInstances;
        $this->varInstanceNum = $varInstanceNum;
    }

    public function loadClusterConfiguration(): ClusterConfiguration
    {
        $sInstanceNum = getenv($this->varInstanceNum);
        if (!ctype_digit($sInstanceNum)) {
            $errMsg = "Instance Number is not an integer value: {$this->varInstanceNum}={$sInstanceNum}";
            throw new ClusterConfigurationValidationException($errMsg);
        }
        $instanceNumber = intval($sInstanceNum);

        $sNumOfInstances = getenv($this->varNumOfInstances);
        if (!ctype_digit($sNumOfInstances)) {
            $errMsg = "Number of instances is not an integer value: {$this->varNumOfInstances}={$sNumOfInstances}";
            throw new ClusterConfigurationValidationException($errMsg);
        }
        $numberOfInstances = intval($sNumOfInstances);
        return new ClusterConfiguration($instanceNumber, $numberOfInstances);
    }

}
