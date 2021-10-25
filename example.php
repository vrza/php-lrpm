#!/usr/bin/env php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use PHPLRPM\Test\MockConfigurationSource;
use PHPLRPM\ProcessManager;

$configurationSource = new MockConfigurationSource();
$processManager = new ProcessManager($configurationSource);
$processManager->run();
