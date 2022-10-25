<?php

namespace PHPLRPM\Cluster;

class FileBasedClusterConfiguration implements ClusterConfiguration
{
    const INVALID_VALUE = -1;

    private $path;

    private $numberOfInstances = self::INVALID_VALUE;
    private $instanceNumber = self::INVALID_VALUE;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function reload()
    {
        $contents = file_get_contents($this->path);
        if ($contents === false) {
            $errMsg = "Error reading cluster configuration file: {$this->path}";
            throw new ClusterConfigurationFileReadingException($errMsg);
        }
        $config = json_decode($contents, true);
        if (is_null($config)) {
            $errMsg = "Error parsing cluster configuration file: {$this->path}";
            throw new ClusterConfigurationFileReadingException($errMsg);
        }

        if (!is_int($config['numberOfInstances'])) {
            $errMsg = "Number of instances is not an integer value: {$config['numberOfInstances']}";
            throw new ClusterConfigurationValidationException($errMsg);
        }
        $this->numberOfInstances = $config['numberOfInstances'];
        if (!is_int($config['instanceNumber'])) {
            $errMsg = "Instance number is not an integer value: {$config['instanceNumber']}";
            throw new ClusterConfigurationValidationException($errMsg);
        }
        $this->instanceNumber = $config['instanceNumber'];
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
