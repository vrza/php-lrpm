<?php

namespace PHPLRPM;

class WorkerProcess {
    private const EXIT_SUCCESS = 0;
    private const EXIT_PPID_CHANGED = 2;

    private $worker;

    private $ppid = -1;
    private $shouldRun = true;

    public function __construct(Worker $worker)
    {
        $this->worker = $worker;
        pcntl_signal(SIGTERM,  function (int $signo, $_siginfo) {
            fwrite(STDOUT, "--> Worker caught SIGTERM ($signo)" . PHP_EOL);
            $this->shutdown_signal_handler($signo);
        });
        pcntl_signal(SIGINT,  function (int $signo, $_siginfo) {
            fwrite(STDOUT, "--> Worker caught SIGINT ($signo)" . PHP_EOL);
            $this->shutdown_signal_handler($signo);
        });
    }

    private function shutdown_signal_handler(int $signo): void
    {
        fwrite(STDOUT, "--> Worker shutdown signal handler " . $signo . PHP_EOL);
        $this->shouldRun = false;
    }

    private function checkParent(): void
    {
        $ppid = posix_getppid();
        if ($ppid != $this->ppid) {
            fwrite(STDERR, "--> Parent PID changed, worker process exiting" . PHP_EOL);
            exit(self::EXIT_PPID_CHANGED);
        }
    }

    public function work($config): void
    {
        $this->ppid = posix_getppid();
        $this->worker->start($config);
        fwrite(STDOUT, "--> Starting Worker loop" . PHP_EOL);
        while ($this->shouldRun) {
            $this->worker->cycle();
            $this->checkParent();
            pcntl_signal_dispatch();
        }
        fwrite(STDOUT, "--> Worker shutdown requested, exiting" . PHP_EOL);
        exit(self::EXIT_SUCCESS);
    }
}
