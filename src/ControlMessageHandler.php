<?php

namespace PHPLRPM;

use TIPC\MessageHandler;

class ControlMessageHandler implements MessageHandler
{
    private $processManager;

    public function __construct(ProcessManager $processManager)
    {
        $this->processManager = $processManager;
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

}
