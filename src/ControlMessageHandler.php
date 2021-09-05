<?php

namespace PHPLRPM;

use TIPC\MessageHandler;
use TIPC\UnixSocketStreamServer;

class ControlMessageHandler implements MessageHandler
{
    private $messageServer;
    private $processManager;

    public function __construct(ProcessManager $processManager)
    {
        $this->processManager = $processManager;
        $this->initializeMessageServer();
    }

    public function startMessageListener(): void
    {
        if (!is_null($this->messageServer)) {
            fwrite(STDOUT, '==> Starting control message listener service' . PHP_EOL);
            $this->messageServer->listen();
        }
    }

    public function checkMessages(): void
    {
        if (!is_null($this->messageServer)) {
            $this->messageServer->checkMessages();
        }
    }

    public function handleMessage(string $msg): string
    {
        $help = 'valid messages:
  help                 description of valid messages
  status, jsonstatus   information about running worker processes
  restart <job_id>     restart process with job id <job_id>
  reload               reload configuration
  stop                 shut down lrpm and all worker processes';
        $args = explode(' ', $msg);
        switch ($args[0]) {
            case 'help':
                return "lrpm: $help";
            case 'jsonstatus':
            case 'status':
                return json_encode($this->processManager->getStatus());
            case 'stop':
                $this->processManager->shutdown();
                return 'lrpm: Shutting down process manager';
            case 'restart':
                return isset($args[1])
                    ? $this->processManager->scheduleRestartOnDemand($args[1])
                    : 'lrpm: restart requires a job id argument';
            case 'reload':
                $this->processManager->scheduleConfigReload();
                return 'Scheduled immediate configuration reload';
            default:
                return "lrpm: '$msg' is not a valid message. $help";
        }
    }

    private function initializeMessageServer()
    {
        $socketDirs = [
            '/run/php-lrpm',
            '/run/user/' . posix_geteuid() . '/php-lrpm'
        ];
        $socketFileName = 'socket';
        if (($socketDir = $this->ensureWritableDir($socketDirs)) !== false) {
            fwrite(STDERR, "==> Unix domain socket for control messages: $socketDir" . PHP_EOL);
            $socketPath = $socketDir . '/' . $socketFileName;
            $this->messageServer = new UnixSocketStreamServer($socketPath, $this);
        } else {
            fwrite(STDERR, "Could not find a writable directory for Unix domain socket" . PHP_EOL);
            fwrite(STDERR, "Ensure one of these is writable: " . implode(', ', $socketDirs) . PHP_EOL);
            fwrite(STDERR, "==> Control messages disabled" . PHP_EOL);
        }
    }

    /**
     * @param array $candidateDirs
     * @return string|false
     */
    private function ensureWritableDir(array $candidateDirs)
    {
        foreach ($candidateDirs as $candidateDir) {
            @mkdir($candidateDir, 0700, true);
            if (file_exists($candidateDir) && is_dir($candidateDir) && is_writable($candidateDir)) {
                return $candidateDir;
            }
        }
        return false;
    }

}
