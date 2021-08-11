<?php

namespace PHPLRPM;

class WorkerProcess {
    const EXIT_SUCCESS = 0;
    const EXIT_PPID_CHANGED = 2;

    private $worker;

    private $ppid = -1;
    private $shutdown = false;

    public function __construct(Worker $worker)
    {
        $this->worker = $worker;
        pcntl_signal(SIGTERM,  function (int $signo, $_siginfo) {
            fwrite(STDOUT, "==> Caught SIGTERM" . PHP_EOL);
            $this->shutdown_signal_handler($signo);
        });
        pcntl_signal(SIGINT,  function (int $signo, $_siginfo) {
            fwrite(STDOUT, "==> Caught SIGINT" . PHP_EOL);
            $this->shutdown_signal_handler($signo);
        });
    }

    private function shutdown_signal_handler(int $signo): void
    {
        fwrite(STDOUT, "==> Shutdown signal handler " . $signo . PHP_EOL);
        $this->shutdown = true;
    }

    private function checkParent(): void
    {
        $ppid = posix_getppid();
        if ($ppid != $this->ppid) {
            fwrite(STDERR, "--> Parent PID changed, exiting" . PHP_EOL);
            exit(self::EXIT_PPID_CHANGED);
        }
    }

    public function work($config): void
    {
        $this->ppid = posix_getppid();
        $this->worker->start($config);
        while (true) { // worker loop
            //$this->testCycle(); // BREAKME to test zombie reaping
            //$this->cycle($arg);
            // run a cycle of business logic
            $this->worker->cycle();
            $this->checkParent();
            pcntl_signal_dispatch();
            if ($this->shutdown) {
                fwrite(STDOUT, "--> Shutdown requested, exiting" . PHP_EOL);
                exit(self::EXIT_SUCCESS);
            }
        }
    }
}
