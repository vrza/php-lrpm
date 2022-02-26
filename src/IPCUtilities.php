<?php

namespace PHPLRPM;

class IPCUtilities
{
    public static function getSocketDirs(): array
    {
        return [
            '/run/php-lrpm',
            '/run/user/' . posix_geteuid() . '/php-lrpm'
        ];
    }
}
