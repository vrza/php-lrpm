<?php

namespace PHPLRPM\Cluster;

class FileBasedClusterConfiguration implements ClusterConfiguration
{
    private $numberOfInstances = 1;
    private $instanceNumber = 0;

    public function __construct(string $path)
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            $errorMsg = "Error reading cluster configuration file: $path";
            throw new ConfigFileReadingException($errorMsg);
        }
        $config = json_decode($contents, true);
        if (is_null($config)) {
            $errorMsg = "Error parsing cluster configuration file: $path";
            throw new ConfigFileReadingException($errorMsg);
        }
        $this->numberOfInstances = $config['numberOfInstances'];
        $this->instanceNumber = $config['instanceNumber'];
    }

    public function instanceNumber(): int
    {
        return $this->instanceNumber;
    }

    public function numberOfInstances(): int
    {
        return $this->numberOfInstances;
    }
}
