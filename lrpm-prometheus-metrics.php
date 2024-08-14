<?php
# php -S 127.0.0.1:8080 lrpm-prometheus-metrics.php

class PrometheusMetric
{
    private $name;
    private $value;
    private $labels;
    private $timestamp;

    public function __construct(string $name, $value, array $labels = [], $timestamp = null)
    {
        $this->name = $name;
        $this->value = $value;
        $this->labels = $labels;
        $this->timestamp = $timestamp;
    }

    public function __toString(): string
    {
        $output = $this->name;
        if (!empty($this->labels)) {
            $output .= '{';
            $sep = '';
            foreach ($this->labels as $labelName => $labelValue) {
                $output .= $sep . $labelName . '=' . $labelValue;
                $sep = ',';
            }
            $output .= '}';
        }
        $output .= ' ' . $this->value;
        if (isset($this->timestamp)) $output .= ' ' . $this->timestamp;
        return $output;
    }
}

class PrometheusMetricsBuilder
{
    private $metrics = [];
    private $text = '';

    public function add(PrometheusMetric $metric): void
    {
        $this->metrics[] = $metric;
    }

    public function __toString(): string
    {
        foreach ($this->metrics as $metric) {
            $this->text .= $metric->__toString() . PHP_EOL;
        }
        return $this->text;
    }
}

function get_number_of_active_tasks(array $status): int
{
    $active_tasks = array_filter($status, function ($task) {
        return isset($task['state']['pid']) && is_int($task['state']['pid']);
    });
    return count($active_tasks);
}

function lrpm_status_to_prometheus_metrics(array $status): string
{
    $metricsBuilder = new PrometheusMetricsBuilder();

    $taskCount = count($status);
    $metricsBuilder->add(new PrometheusMetric('lrpm_task_count', $taskCount));

    $activeTaskCount = get_number_of_active_tasks($status);
    $inactiveTaskCount = $taskCount - $activeTaskCount;
    $metricsBuilder->add(new PrometheusMetric('lrpm_inactive_task_count', $inactiveTaskCount));

    foreach ($status as $jobId => $jobStatus) {
        $config = $jobStatus['config'];
        $state = $jobStatus['state'];
        $name = $config['name'];
        $workerClass = $config['workerClass'];
        $mtime = $config['mtime'];
        if (isset($state['restartAt']) && $state['restartAt'] > $state['startedAt']) {
            $backoffDuration = $state['restartAt'] - $state['startedAt'];
            $labels = [
                'id' => $jobId,
                'name' => $name
            ];
            if (isset($state['lastExitCode'])) $labels['last_exit_code'] = $state['lastExitCode'];
            $metricsBuilder->add(new PrometheusMetric('lrpm_task_backoff', $backoffDuration, $labels));
        }
    }

    return $metricsBuilder->__toString();
}

// main entry point

$program = __DIR__ . '/bin/lrpmctl';
$request = 'jsonstatus';
$json = shell_exec("$program $request");
$status = json_decode($json, true);
$output = lrpm_status_to_prometheus_metrics($status);
echo($output);
