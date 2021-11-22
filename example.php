#!/usr/bin/env php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use PHPLRPM\Test\MockConfigurationSource;
use PHPLRPM\ProcessManager;

$processManager = new ProcessManager(MockConfigurationSource::class);
$processManager->run();
