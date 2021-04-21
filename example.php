#!/usr/bin/env php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use \PHPLRPM\MockDatabase;
use \PHPLRPM\ProcessManager;

const EXIT_INVALID_ARGUMENTS = 1;

if (count($argv) < 2) {
    fwrite(STDERR, "Usage: {$argv[0]} argument" . PHP_EOL);
    exit(EXIT_INVALID_ARGUMENTS);
}

$db = new MockDatabase();
$pm = new ProcessManager($db);
$pm->run();
