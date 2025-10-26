<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');


// Check for autoload.php in current directory first, then try parent directory
$autoloadPath = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    $autoloadPath = __DIR__ . '/../../../vendor/autoload.php';

    echo $autoloadPath;
    if (!file_exists($autoloadPath)) {
        die("Could not find autoload.php in vendor directory\n");
    }
}
require_once $autoloadPath;

use Syntaxx\WebAssemblyPacker\Options;
use Syntaxx\WebAssemblyPacker\Infra\EventManager;
use Syntaxx\WebAssemblyPacker\Infra\Events\LogEvent;
use Syntaxx\WebAssemblyPacker\WebAssemblyPacker;

// Create event manager
$eventManager = new EventManager();

// Set up default console output handler
$eventManager->addListener(LogEvent::class, function(LogEvent $event) {
    $output = (string)$event . PHP_EOL;
    if ($event->getLevel() === LogEvent::LEVEL_ERROR) {
        fwrite(STDERR, $output);
    } else {
        echo $output;
    }
});

$cwd = getcwd();
if ($cwd === false) {
    $eventManager->error("Could not get current working directory.");
    exit(1);
}

if ($argc <= 1) {
    $eventManager->error("Usage: php file_packager.php TARGET [--preload A [B..]] [--embed C [D..]] [--js-output=OUTPUT.js] [--no-force] ...");
    exit(1);
}

$options = Options::fromCliArgs($argc, $argv, $eventManager, $cwd);

if ($options->debug) {
    $eventManager->setLogLevel(LogEvent::LEVEL_DEBUG);
}

$webAssemblyPacker = new WebAssemblyPacker($eventManager);
$webAssemblyPacker->pack($options, $argv);
