<?php

namespace PHPLRPM;

class WorkerProcess {
    private $workerClassName;
    private $ppid = -1;
    private $shouldRun = true;

    public function __construct(string $workerClassName)
    {
        $this->workerClassName = $workerClassName;
        pcntl_signal(SIGTERM,  function (int $signo, $_siginfo) {
            Log::getInstance()->info("--> Worker caught SIGTERM ($signo)");
            $this->shutdown_signal_handler($signo);
        });
        pcntl_signal(SIGINT,  function (int $signo, $_siginfo) {
            Log::getInstance()->info("--> Worker caught SIGINT ($signo)");
            $this->shutdown_signal_handler($signo);
        });
    }

    private function shutdown_signal_handler(int $signo): void
    {
        Log::getInstance()->info("--> Worker shutdown signal handler " . $signo);
        $this->shouldRun = false;
    }

    private function checkParent(): void
    {
        $ppid = posix_getppid();
        if ($ppid != $this->ppid) {
            Log::getInstance()->info("--> Parent PID changed, worker process exiting");
            exit(ExitCodes::EXIT_PPID_CHANGED);
        }
    }

    public function work($config): void
    {
        $this->ppid = posix_getppid();
        Log::getInstance()->info("--> Initializing Worker (" . $this->workerClassName . ")");
        $worker = new $this->workerClassName();
        $worker->start($config);
        Log::getInstance()->info("--> Entering Worker loop (" . $this->workerClassName . ")");
        while ($this->shouldRun) {
            $worker->cycle();
            $this->checkParent();
            pcntl_signal_dispatch();
        }
        Log::getInstance()->info("--> Worker shutdown requested, exiting (" . $this->workerClassName . ")");
        exit(ExitCodes::EXIT_SUCCESS);
    }
}
