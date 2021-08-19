<?php

namespace PHPLRPM;

class ConfigurationValidator
{
    private $config;
    private $fields;
    private $errors = [];

    public function __construct(array $config)
    {
        $this->fields = [
            'mtime' => [
                'description' => 'Time of last modification, must be a UTC Unix timestamp integer',
                'mandatory' => true,
                'valid' => function($mtime) {
                    return is_int($mtime) && $mtime >= 0;
                }
            ],
            'name' => [
                'description' => 'Descriptive job name, must be a string',
                'mandatory' => true,
                'valid' => function($name) {
                    return is_string($name);
                }
            ],
            'workerConfig' => [
                'description' => 'Worker-specific configuration, must be an array',
                'mandatory' => true,
                'valid' => function($workerConfig) {
                    return is_array($workerConfig);
                }
            ],
            'workerClass' => [
                'description' => 'Worker class, must be a string referencing an existing class',
                'mandatory' => true,
                'valid' => function($workerClass) {
                    return is_string($workerClass) && class_exists($workerClass);
                }
            ],
            'shortRunTimeSeconds' => [
                'description' => 'Minimum number of seconds a process is expected to run',
                'mandatory' => false,
                'default' => 5,
                'valid' => function($shortRunTimeSeconds) {
                    return is_int($shortRunTimeSeconds) && $shortRunTimeSeconds >= 0;
                }
            ]
        ];
        $this->config = $config;
    }

    public function isValid(): bool
    {
        $this->errors = [];
        foreach ($this->fields as $field => $v) {
            // verify that all mandatory fields are present
            if ($v['mandatory'] && !array_key_exists($field, $this->config)) {
                $this->errors[$field]['error'] = 'Field missing';
                $this->errors[$field]['description'] = $v['description'];
                continue;
            }
            // fill in default values for missing non-mandatory fields
            if (!$v['mandatory'] && !array_key_exists($field, $this->config)) {
                $this->config[$field] = $v['default'];
            }
            // validate field values
            if (!$v['valid']($this->config[$field])) {
                $this->errors[$field]['error'] = 'Invalid data';
                $this->errors[$field]['description'] = $v['description'];
            }
        }
        return count($this->errors) == 0;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public static function filter(array $configs): array
    {
        $filtered = [];
        foreach ($configs as $jobId => $jobConfig) {
            $validator = new self($jobConfig);
            if ($validator->isValid()) {
                $filtered[$jobId] = $validator->getConfig();
            } else {
                fwrite(
                    STDERR,
                    "Invalid configuration for job $jobId: " .
                    json_encode($validator->getErrors()) . PHP_EOL
                );
            }
        }
        return $filtered;
    }
}
