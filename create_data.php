<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/vendor/autoload.php'; // Include Composer's autoloader

use PHPX\WebAssemblyPacker\Options;
use PHPX\WebAssemblyPacker\FilesExtractor;
use PHPX\WebAssemblyPacker\DataPacker;
use PHPX\WebAssemblyPacker\LZ4Compressor;
use PHPX\WebAssemblyPacker\JS\JSTemplates;
use PHPX\WebAssemblyPacker\Infra\EventManager;
use PHPX\WebAssemblyPacker\Infra\Events\LogEvent;
use PHPX\WebAssemblyPacker\WebAssemblyPacker;

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

$options = Options::fromCliArgs($argc, $argv, $eventManager);

$webAssemblyPacker = new WebAssemblyPacker($eventManager);
$webAssemblyPacker->pack($options, $argv, $cwd);

die;


$dataTarget = $argv[1];
$initialDataFiles = $options->initialDataFiles;

$filesExtractor = new FilesExtractor($eventManager);
$allDataFiles = $filesExtractor->process($options, $cwd, $initialDataFiles);

$dataPacker = new DataPacker($eventManager);
[$metadataFiles, $totalBytesWrittenUncompressed, $tempDataFile] = $dataPacker->pack($options, $dataTarget, $allDataFiles);

$nodeCheck = "typeof process === 'object' && typeof process.versions === 'object' && typeof process.versions.node === 'string'"; // Default Node.js check

$compressedSize = null;
if ($options->lz4 && $tempDataFile) {
    $lz4Compressor = new LZ4Compressor($eventManager);
    $compressedSize = $lz4Compressor->compress($tempDataFile, $dataTarget, $options);
} elseif (!$options->lz4) {
}

if (!$options->hasPreloaded && !$options->hasEmbedded) {
    $eventManager->warning("Nothing to preload or embed.");
    if (!$options->force) exit(0);
}

$eventManager->info("Processed " . count($allDataFiles) . " files.");
if (file_exists($dataTarget)) {
    $size = filesize($dataTarget);
    $status = $options->lz4 ? " (compressed)" : "";
    $eventManager->info("Data file created: {$dataTarget} ({$size} bytes{$status})");
} else {
    $eventManager->error("Data file NOT created: {$dataTarget}");
}

$jsTemplates = new JSTemplates($eventManager);
$jsCode = $jsTemplates->fillTemplate(
    $options,
    $dataTarget,
    $allDataFiles,
    $metadataFiles,
    $totalBytesWrittenUncompressed,
    $compressedSize
);

file_put_contents($options->jsOutput, $jsCode);

if ($options->jsOutput && file_exists($options->jsOutput)) {
    $eventManager->info("JS output created: {$options->jsOutput}");
} elseif ($options->jsOutput) {
    $eventManager->error("JS output NOT created: {$options->jsOutput}");
}
