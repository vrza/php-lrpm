<?php

namespace PHPLRPM;

class ProcessUtilities {
    public static function reapAnyChildren(): array {
        $results = [];
        while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
            fwrite(STDOUT, "Child with PID $pid exited with status $status" . PHP_EOL);
            $results[$pid] = $status;
        }
        return $results;
    }
}
