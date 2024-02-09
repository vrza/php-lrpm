<?php

namespace PHPLRPM;

class ProcessUtilities
{
    public static function reapAnyChildren(): array
    {
        $results = [];
        while (($pid = pcntl_waitpid(-1, $status, WNOHANG | WUNTRACED | WCONTINUED)) > 0) {
            if (pcntl_wifexited($status)) {
                $results[$pid] = $status;
                $exit_code = pcntl_wexitstatus($status);
                $message = "Child with PID $pid exited with code $exit_code";
                fwrite(STDOUT, $message . PHP_EOL);
            } elseif (pcntl_wifsignaled($status)) {
                $results[$pid] = $status;
                $signal = pcntl_wtermsig($status);
                $message = "Child with PID $pid terminated by signal $signal";
                if ($signalName = self::signalName($signal)) {
                    $message .= " $signalName";
                }
                fwrite(STDOUT, $message . PHP_EOL);
            } elseif (self::stoppedOrContinued($pid, $status)) {
                continue;
            } else {
                fwrite(STDERR, sprintf("Unexpected status (0x%x) for child PID %d", $status, $pid) . PHP_EOL);
            }
        }
        return $results;
    }

    private static function stoppedOrContinued($pid, $status)
    {
        if (pcntl_wifcontinued($status)) {
            $message = "Child with PID $pid continued with signal 18 SIGCONT";
            fwrite(STDOUT, $message . PHP_EOL);
            return true;
        }

        if (pcntl_wifstopped($status)) {
            $signal = pcntl_wstopsig($status);
            $message = "Child with PID $pid stopped with signal $signal";
            if ($signalName = self::signalName($signal)) {
                $message .= " $signalName";
            }
            fwrite(STDOUT, $message . PHP_EOL);
            return true;
        }

        return false;
    }

    private static function signalName(int $signo): ?string
    {
        static $map = [];
        if (empty($map)) {
            foreach (get_defined_constants(true)['pcntl'] as $name => $number) {
                if (substr($name, 0, 3) === "SIG" && $name[3] !== "_") {
                    $map[$number] = $name;
                }
            }
        }
        return $map[$signo] ?? null;
    }
}
