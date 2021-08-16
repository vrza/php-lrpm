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
                'description' => 'Time of last modification, must be a UTC UNIX timestamp',
                'valid' => function($mtime) {
                    return is_int($mtime) && $mtime >= 0;
                }
            ],
            'name' => [
                'description' => 'Descriptive job name, must be a string',
                'valid' => function($name) {
                    return is_string($name);
                }
            ],
            'workerConfig' => [
                'description' => 'Worker-specific configuration, must be an array',
                'valid' => function($workerConfig) {
                    return is_array($workerConfig);
                }
            ],
            'workerClass' => [
                'description' => 'Worker class, must be a string referencing an existing class',
                'valid' => function($workerClass) {
                    return is_string($workerClass) && class_exists($workerClass);
                }
            ]
        ];
        $this->config = $config;
    }

    public function isValid(): bool
    {
        $this->errors = [];
        foreach ($this->fields as $field => $v) {
            if (!array_key_exists($field, $this->config)) {
                $this->errors[$field]['error'] = 'Field missing';
                $this->errors[$field]['description'] = $v['description'];
                continue;
            }
            if (!$v['valid']) {
                $this->errors[$field]['error'] = 'Invalid data';
                $this->errors[$field]['description'] = $v['description'];
            }
        }
        return count($this->errors) == 0;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
