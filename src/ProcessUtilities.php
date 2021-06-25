<?php

namespace PHPLRPM;

class ProcessUtilities {
    public static function reapAnyChildren(): array {
        $results = [];
        $pid = null;
        while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
            fwrite(STDOUT, "wait pid: " . $pid . PHP_EOL);
            fwrite(STDOUT, "wait status: " . $status . PHP_EOL);
            $results[$pid] = $status;
        }
        if ($pid !== null) {
            fwrite(STDOUT, "reapAnyChildren results: " . $pid . PHP_EOL);
            var_dump($results);
        }
        return $results;
    }
}
