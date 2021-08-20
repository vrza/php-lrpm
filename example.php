#!/usr/bin/env php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use PHPLRPM\Test\MockConfigurationSource;
use PHPLRPM\ProcessManager;

$db = new MockConfigurationSource();
$pm = new ProcessManager($db);
$pm->run();
