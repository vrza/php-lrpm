<?php

namespace PHPLRPM\Cluster;

class EnvironmentBasedClusterConfiguration implements ClusterConfiguration
{
    const DEFAULT_VAR_NUM_OF_INSTANCES = 'PHP_LRPM_NUMBER_OF_INSTANCES';
    const DEFAULT_VAR_INSTANCE_NUM = 'PHP_LRPM_INSTANCE_NUMBER';
    const INVALID_VALUE = -1;

    private $varNumOfInstances;
    private $varInstanceNum;

    private $numberOfInstances = self::INVALID_VALUE;
    private $instanceNumber = self::INVALID_VALUE;

    public function __construct(
        string $varNumOfInstances = self::DEFAULT_VAR_NUM_OF_INSTANCES,
        string $varInstanceNum = self::DEFAULT_VAR_INSTANCE_NUM
    ) {
        $this->varNumOfInstances = $varNumOfInstances;
        $this->varInstanceNum = $varInstanceNum;
    }

    public function reload()
    {
        $sInstanceNum = getenv($this->varInstanceNum);
        if (!ctype_digit($sInstanceNum)) {
            $errMsg = "Instance Number is not an integer value: {$this->varInstanceNum}={$sInstanceNum}";
            throw new ClusterConfigurationValidationException($errMsg);
        }
        $this->instanceNumber = intval($sInstanceNum);

        $sNumOfInstances = getenv($this->varNumOfInstances);
        if (!ctype_digit($sNumOfInstances)) {
            $errMsg = "Number of instances is not an integer value: {$this->varNumOfInstances}={$sNumOfInstances}";
            throw new ClusterConfigurationValidationException($errMsg);
        }
        $this->numberOfInstances = intval($sNumOfInstances);
    }

    public function getInstanceNumber(): int
    {
        return $this->instanceNumber;
    }

    public function getNumberOfInstances(): int
    {
        return $this->numberOfInstances;
    }
}
