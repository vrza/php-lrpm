<?php

namespace PHPLRPM;

use VladimirVrzic\Simplogger\Logger;
use VladimirVrzic\Simplogger\StdoutLogger;

class Log
{
    private static $instance;

    public static function getInstance(): Logger
    {
        if (is_null(self::$instance)) {
            self::$instance = new StdoutLogger(true, true);
        }
        return self::$instance;
    }

    public static function setInstance(Logger $instance): void
    {
        self::$instance = $instance;
    }

}
