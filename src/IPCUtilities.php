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

    public static function serverFindUnixSocket(string $socketFileName, array $socketDirs): ?string
    {
        if (is_null($socketDir = self::ensureWritableDir($socketDirs))) {
            fwrite(STDERR, "Could not find a writable directory for $socketFileName Unix domain socket" . PHP_EOL);
            fwrite(STDERR, "Ensure one of these is writable: " . implode(', ', $socketDirs) . PHP_EOL);
            return null;
        }
        return $socketDir . '/' . $socketFileName;
    }

    public static function clientFindUnixSocket(string $socketFileName, array $socketDirs): ?string
    {
        foreach ($socketDirs as $dir) {
            $candidate = $dir . '/' . $socketFileName;
            if (is_writable($candidate)) {
                return $candidate;
            }
        }
        fwrite(STDERR, "Could not find Unix domain socket: $socketFileName" . PHP_EOL);
        fwrite(
            STDERR,
            "Tried: " . implode(
                ', ',
                array_map(function ($dir) use ($socketFileName) {
                    return $dir . '/' . $socketFileName;
                }, $socketDirs)
            ) . PHP_EOL
        );
        return null;
    }

    /**
     * Try to find a writable directory from a list of candidates,
     * possibly creating a new directory if possible.
     *
     * We are intentionally suppressing errors when attempting to create
     * directories, regardless of the reason (file exists,
     * insufficient permissions...), as this is not a critical failure.
     *
     * Returns path to a writeable directory, or false if a writeable
     * directory is not available.
     *
     * @param array $candidateDirs
     * @return string|false
     */
    private static function ensureWritableDir(array $candidateDirs): ?string
    {
        foreach ($candidateDirs as $candidateDir) {
            if (!file_exists($candidateDir)) {
                set_error_handler(function () {});
                @mkdir($candidateDir, 0700, true);
                restore_error_handler();
            }
            if (is_dir($candidateDir) && is_writable($candidateDir)) {
                return $candidateDir;
            }
        }
        return null;
    }

}
