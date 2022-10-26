<?php

namespace PHPLRPM\Cluster;

class FileBasedClusterConfigurationProvider implements ClusterConfigurationProvider
{
    private $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function loadClusterConfiguration(): ClusterConfiguration
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
        $numberOfInstances = $config['numberOfInstances'];

        if (!is_int($config['instanceNumber'])) {
            $errMsg = "Instance number is not an integer value: {$config['instanceNumber']}";
            throw new ClusterConfigurationValidationException($errMsg);
        }
        $instanceNumber = $config['instanceNumber'];

        return new ClusterConfiguration($instanceNumber, $numberOfInstances);
    }

}
