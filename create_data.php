<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/vendor/autoload.php'; // Include Composer's autoloader

use PHPX\WebAssemblyPacker\Options;
use PHPX\WebAssemblyPacker\DataFile;
use PHPX\WebAssemblyPacker\FilesExtractor;
use PHPX\WebAssemblyPacker\DataPacker;
use PHPX\WebAssemblyPacker\LZ4Compressor;

if ($argc <= 1) {
    fwrite(STDERR, "Usage: php file_packager.php TARGET [--preload A [B..]] [--embed C [D..]] [--js-output=OUTPUT.js] [--no-force] ...\n");
    exit(1);
}

$allDataFiles = []; // All files after expanding directories
$dataTarget = $argv[1];

$options = Options::fromCliArgs($argc, $argv);
$initialDataFiles = $options->initialDataFiles;

$cwd = getcwd();
if ($cwd === false) {
    fwrite(STDERR, "Error: Could not get current working directory.\n");
    exit(1);
}

$filesExtractor = new FilesExtractor();
$allDataFiles = $filesExtractor->process($options, $cwd, $initialDataFiles);

$dataPacker = new DataPacker();
[$metadataFiles, $totalBytesWrittenUncompressed, $tempDataFile] = $dataPacker->pack($options, $dataTarget, $allDataFiles);

$nodeCheck = "typeof process === 'object' && typeof process.versions === 'object' && typeof process.versions.node === 'string'"; // Default Node.js check

if ($options->lz4 && $tempDataFile) {
    $lz4Compressor = new LZ4Compressor();
    $compressedSize = $lz4Compressor->compress($tempDataFile, $dataTarget, $options);
} elseif (!$options->lz4) {
}

if (!$options->hasPreloaded && !$options->hasEmbedded) {
    fwrite(STDERR, "Nothing to preload or embed.\n");
    if (!$options->force) exit(0);
}

echo "PHP File Packager: Processed " . count($allDataFiles) . " files.\n";
if (file_exists($dataTarget)) {
    $size = filesize($dataTarget);
    $status = $options->lz4 ? " (compressed)" : "";
    echo "Data file created: {$dataTarget} ({$size} bytes{$status})\n";
} else {
     echo "Data file NOT created: {$dataTarget}\n"; // Indicate if data file wasn't created
}

$createPaths = [];
foreach ($allDataFiles as $file) {
    $path = $file->dstPath;
    $segments = explode('/', trim($path, '/'));
    $current = '';
    for ($i = 0; $i < count($segments) - 1; $i++) {
        $parent = $current === '' ? '/' : '/' . $current;
        $dir = $segments[$i];
        $fullPath = $parent . '/' . $dir;
        if (!isset($createdPaths[$fullPath])) {
            $createPaths[] = 'Module["FS_createPath"]("' . $parent . '", "' . $dir . '", true, true);' . PHP_EOL;
            $createdPaths[$fullPath] = true;
        }
        $current .= ($current ? '/' : '') . $dir;
    }
}

$data = file_get_contents($dataTarget);
$packageUuid = 'sha256-' . hash('sha256', $data);

if ($options->lz4) {
    $metadataArray = ['files' => $metadataFiles, 'remote_package_size' => $compressedSize, 'package_uuid' => $packageUuid];
    $metadataJson  = json_encode($metadataArray, JSON_UNESCAPED_SLASHES);

    $jsCode = strtr(
        file_get_contents(__DIR__ . '/template/compress.data.js'),
        [
            '#module_name#' => $options->exportName,
            '#package_name#' => $dataTarget,
            '#remote_package_base#' => basename($dataTarget),
            '#data_file#' => 'datafile_build/'.basename($dataTarget),
            '#package_content#' => $metadataJson,
            '#create_paths#' => implode('', $createPaths),
            '#uncompresed_size#' => $totalBytesWrittenUncompressed,
        ]
    );
} else {
    $metadataArray = ['files' => $metadataFiles, 'remote_package_size' => $totalBytesWrittenUncompressed, 'package_uuid' => $packageUuid];
    $metadataJson  = json_encode($metadataArray, JSON_UNESCAPED_SLASHES);

    $jsCode = strtr(
        file_get_contents(__DIR__ . '/template/no-compress.data.js'),
        [
            '#module_name#' => $options->exportName,
            '#package_name#' => $dataTarget,
            '#remote_package_base#' => basename($dataTarget),
            '#data_file#' => 'datafile_build/'.basename($dataTarget),
            '#package_content#' => $metadataJson,
            '#create_paths#' => implode('', $createPaths),
        ]
    );
}        

file_put_contents($options->jsOutput, $jsCode);

if ($options->jsOutput && file_exists($options->jsOutput)) {
    echo "JS output created: {$options->jsOutput}\n";
} elseif ($options->jsOutput) {
     echo "JS output NOT created: {$options->jsOutput}\n"; // Indicate if JS file wasn't created
}
