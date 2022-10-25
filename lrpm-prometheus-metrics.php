<?php
# php -S 127.0.0.1:8080 lrpm-prometheus-metrics.php

$program = __DIR__ . '/bin/lrpmctl';
$request = 'jsonstatus';
$json = shell_exec("$program $request");
$status = json_decode($json, true);
$output = lrpm_status_to_prometheus_metrics($status);
echo($output);

function lrpm_status_to_prometheus_metrics(array $status): string
{
    $taskCount = count($status);
    $activeTaskCount = get_number_of_active_tasks($status);
    $inactiveTaskCount = $taskCount - $activeTaskCount;
    $output = "lrpm_task_count $taskCount" . PHP_EOL;
    $output .= "lrpm_inactive_task_count $inactiveTaskCount" . PHP_EOL;

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
            $metric = serialize_prometheus_metric('lrpm_task_backoff', $backoffDuration, $labels);
            $output .= $metric . PHP_EOL;
        }
    }

    return $output;
}

function get_number_of_active_tasks(array $status)
{
    $active_tasks = array_filter($status, function ($task) {
        return isset($task['state']['pid']) && is_int($task['state']['pid']);
    });
    return count($active_tasks);
}

function serialize_prometheus_metric(string $name, $value, array $labels = [], $timestamp = null): string
{
    $output = $name;
    if (!empty($labels)) {
        $output .= '{';
        $sep = '';
        foreach ($labels as $labelName => $labelValue) {
            $output .= $sep . $labelName . '=' . $labelValue;
            $sep = ',';
        }
        $output .= '}';
    }
    $output .= ' ' . $value;
    if (isset($timestamp)) $output .= ' ' . $timestamp;
    return $output;
}
