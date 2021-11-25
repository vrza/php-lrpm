<?php

namespace PHPLRPM;

use TIPC\UnixSocketStreamClient;

class ConfigurationClient
{
    private $configSocket;

    public function findConfigSocket()
    {
        $this->configSocket = IPCUtilities::clientFindUnixSocket(
            ConfigurationService::SOCKET_FILE_NAME,
            IPCUtilities::getSocketDirs()
        );
        if (is_null($this->configSocket)) {
            throw new RuntimeException("Supervisor could not find config process Unix domain socket");
        }
    }

    /**
     * Creates a configuration client and contacts the configuration
     * process with a request to poll for latest configuration.
     *
     * @return array containing latest configuration
     * @throws ConfigurationPollException
     */
    public function pollConfiguration($signalHandlers): array
    {
        $recvBufSize = 8 * 1024 * 1024;
        $client = new UnixSocketStreamClient($this->configSocket, $recvBufSize);
        if ($client->connect() === false) {
            throw new ConfigurationPollException("Could not connect to socket {$this->configSocket}");
        }
        if ($client->sendMessage(ConfigurationService::REQ_POLL_CONFIG_SOURCE) === false) {
            $client->disconnect();
            throw new ConfigurationPollException("Could not send config query message over socket {$this->configSocket}");
        }
        $signals = array_keys($signalHandlers);
        pcntl_sigprocmask(SIG_BLOCK, $signals);
        if (empty($response = $client->receiveMessage())) {
            pcntl_sigprocmask(SIG_UNBLOCK, $signals);
            $client->disconnect();
            throw new ConfigurationPollException("Failed to read config response from {$this->configSocket}");
        }
        pcntl_sigprocmask(SIG_UNBLOCK, $signals);
        $client->disconnect();
        if ($response === ConfigurationService::RESP_ERROR_CONFIG_SOURCE) {
            throw new ConfigurationPollException("Config process at {$this->configSocket} responded with an error message");
        }
        return Serialization::deserialize($response);
    }

}
