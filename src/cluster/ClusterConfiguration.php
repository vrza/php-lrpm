<?php

namespace PHPLRPM\Cluster;

class ClusterConfiguration
{
    private $instanceNumber;
    private $numberOfInstances;

    public function __construct(int $instanceNumber, int $numberOfInstances)
    {
        if (!self::isValidClusterConfig($numberOfInstances, $instanceNumber)) {
            $errorMsg = 'Invalid cluster config: ' .
                self::toString($numberOfInstances, $instanceNumber);
            throw new ClusterConfigurationValidationException($errorMsg);
        }
        $this->instanceNumber = $instanceNumber;
        $this->numberOfInstances = $numberOfInstances;
    }

    public function getInstanceNumber(): int
    {
        return $this->instanceNumber;
    }

    public function getNumberOfInstances(): int
    {
        return $this->numberOfInstances;
    }

    public function __toString(): string
    {
        return self::toString($this->numberOfInstances, $this->instanceNumber);
    }

    private static function isValidClusterConfig(int $numInstances, int $instance): bool
    {
        return $numInstances > 0 && $instance >= 0 && $instance < $numInstances;
    }

    private static function toString(int $numberOfInstances, int $instanceNumber): string
    {
        return 'number of instances = ' . $numberOfInstances .
            ', instance number = ' . $instanceNumber;
    }
}
