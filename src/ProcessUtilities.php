<?php

namespace PHPLRPM;

class ProcessUtilities {
    public static function reapAnyChildren(): array {
        $results = [];
        while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
            $exit_code = pcntl_wexitstatus($status);
            fwrite(STDOUT, "Child with PID $pid exited with code $exit_code" . PHP_EOL);
            $results[$pid] = $exit_code;
        }
        return $results;
    }
}
