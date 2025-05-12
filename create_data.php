<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/vendor/autoload.php'; // Include Composer's autoloader
use Syntaxx\PHPXLZ4\LZ4;

class Options {
    public ?string $jsOutput = null;
    public bool $force = false; // Default matches Python script
    public bool $hasPreloaded = false;
    public bool $hasEmbedded = false; // For future use
    public string $exportName = 'Module'; // Default export name
    public bool $supportNode = true; // Default support node
    public bool $usePreloadCache = false;
    public bool $lz4 = false;
    public array $excludePatterns = []; // Renamed from excludedPatterns
    public ?array $lz4Metadata = null; // To store metadata from lz4-compress.mjs
}

class DataFile {
    public string $srcPath;
    public string $dstPath;
    public string $mode; // 'preload' or 'embed'
    public bool $explicitDstPath;
    public int $dataStart = 0;
    public int $dataEnd = 0;
    public function __construct(string $srcPath, string $dstPath, string $mode, bool $explicitDstPath) {
        $this->srcPath = $srcPath;
        $this->dstPath = $dstPath;
        $this->mode = $mode;
        $this->explicitDstPath = $explicitDstPath;
    }
}

/**
 * Normalizes a path to use forward slashes and remove redundant parts.
 * Based on Python's posixpath.normpath and utils.normalize_path
 */
function normalizePath(string $path): string {
    $path = str_replace('\\', '/', $path);

    $path = preg_replace('#/+#', '/', $path);

    if ($path !== '/' && substr($path, -1) === '/') {
        $path = rtrim($path, '/');
    }
    $parts = explode('/', $path);
    $newParts = [];
    foreach ($parts as $part) {
        if ($part === '.' || $part === '') {
            continue;
        }
        if ($part === '..') {
            array_pop($newParts);
        } else {
            $newParts[] = $part;
        }
    }

    $normalized = implode('/', $newParts);

    if (strpos($path, '/') === 0 && strpos($normalized, '/') !== 0) {
        $normalized = '/' . $normalized;
    }
    if (empty($normalized) && !empty($parts) && $parts[0] === '') {
        return '/';
    }
    if (empty($normalized) && empty($parts)) {
        return '.'; // Match python os.path.normpath('.')
    }


    return $normalized ?: '.'; // Return '.' if normalization results in empty string (e.g. from './')
}

/**
 * Checks if a given path matches any of the exclusion patterns.
 * Uses PHP's fnmatch, similar to Python's fnmatch.
 */
function shouldIgnore(string $path, Options $options): bool {
    $normalizedPath = rtrim(normalizePath($path), '/');
    $basename = basename($normalizedPath);

    foreach ($options->excludePatterns as $pattern) {
        // Convert Windows backslashes to forward slashes in the pattern
        $pattern = str_replace('\\', '/', $pattern);
        
        // Try matching the full path
        if (fnmatch($pattern, $normalizedPath)) {
            fwrite(STDERR, "DEBUG: Excluding '{$path}' due to pattern '{$pattern}'\n");
            return true;
        }
        
        // Try matching just the basename
        if (fnmatch($pattern, $basename)) {
            fwrite(STDERR, "DEBUG: Excluding '{$path}' due to basename pattern '{$pattern}'\n");
            return true;
        }
        
        // Handle directory patterns
        if (strpos($pattern, '/') !== false) {
            $dirPattern = rtrim($pattern, '/') . '/';
            if (strpos($normalizedPath . '/', $dirPattern) === 0) {
                fwrite(STDERR, "DEBUG: Excluding '{$path}' because it's inside excluded directory pattern '{$pattern}'\n");
                return true;
            }
        }
        
        // Handle exact directory matches
        if (is_dir($path) && $normalizedPath === rtrim(normalizePath($pattern), '/')) {
            fwrite(STDERR, "DEBUG: Excluding directory '{$path}' matching pattern '{$pattern}'\n");
            return true;
        }
    }
    return false;
}

/**
 * Recursively finds files in a directory, similar to os.walk.
 * Returns an array of DataFile objects.
 */
function findFilesRecursive(string $srcPath, string $dstPathRoot, string $mode): array {
    global $options; // Make global options accessible

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcPath, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );


    foreach ($iterator as $item) {
        /** @var SplFileInfo $item */
        $fullSrcPath = $item->getPathname();

        if (shouldIgnore($fullSrcPath, $options)) {
            continue; // Skip excluded files/directories
        }
        /** @var SplFileInfo $item */
        $fullSrcPath = $item->getPathname();
        $relativePath = substr($fullSrcPath, strlen($srcPath) + 1); // Relative path within the source dir
        $currentDstPath = normalizePath($dstPathRoot . '/' . $relativePath);

        if ($item->isFile()) {
            $files[] = new DataFile($fullSrcPath, $currentDstPath, $mode, false);
        }
    }
    return $files;
}

if ($argc <= 1) {
    fwrite(STDERR, "Usage: php file_packager.php TARGET [--preload A [B..]] [--embed C [D..]] [--js-output=OUTPUT.js] [--no-force] ...\n");
    exit(1);
}

$options = new Options();
$initialDataFiles = []; // Files specified directly on command line
$allDataFiles = []; // All files after expanding directories

$dataTarget = $argv[1];
$leading = '';

for ($i = 2; $i < $argc; $i++) {
    $arg = $argv[$i];
    fwrite(STDERR, "DEBUG: Processing argument {$i}: '{$arg}'\n"); // Debug output

    if ($arg === '--preload') {
        $leading = 'preload';
    } elseif ($arg === '--embed') {
        $leading = 'embed';
    } elseif ($arg === '--exclude') {
        $leading = 'exclude';
        fwrite(STDERR, "DEBUG: Set leading to 'exclude'\n"); // Debug output
    } elseif ($arg === '--no-force') {
        $options->force = false;
        $leading = '';
    } elseif ($arg === '--use-preload-cache') {
        $options->usePreloadCache = true;
        $leading = '';
    } elseif (strpos($arg, '--js-output=') === 0) {
        $options->jsOutput = substr($arg, strlen('--js-output='));
        $leading = '';
    } elseif ($arg === '--lz4') {
        $options->lz4 = true;
        $leading = '';
    } elseif ($arg === '--no-node') {
        $options->supportNode = false;
        $leading = '';
    } elseif (strpos($arg, '--export-name=') === 0) {
        $options->exportName = substr($arg, strlen('--export-name='));
        var_dump($arg);
        $leading = '';
    } elseif ($leading === 'exclude') {
        // Remove any surrounding quotes from the pattern
        $pattern = trim($arg, "'\"");
        fwrite(STDERR, "DEBUG: Adding exclude pattern: '{$pattern}'\n"); // Debug output
        $options->excludePatterns[] = $pattern;
        // Don't reset leading here to allow multiple patterns
    } elseif ($leading === 'preload' || $leading === 'embed') {
        $mode = $leading;
        $srcPath = $arg;
        $dstPath = $arg;
        $explicitDstPath = false;

        $atPosition = strpos(str_replace('@@', '__', $arg), '@');
        if ($atPosition !== false) {
            $srcPath = str_replace('@@', '@', substr($arg, 0, $atPosition));
            $dstPath = str_replace('@@', '@', substr($arg, $atPosition + 1));
            $explicitDstPath = true;
        } else {
            $srcPath = $dstPath = str_replace('@@', '@', $arg);
        }

        if (!file_exists($srcPath)) {
            fwrite(STDERR, "Error: Input path '{$srcPath}' does not exist.\n");
            exit(1);
        }

        $initialDataFiles[] = new DataFile($srcPath, $dstPath, $mode, $explicitDstPath);
    } else {
        fwrite(STDERR, "Unknown parameter: {$arg}\n");
        $leading = ''; // Reset leading if unknown param encountered
    }
}


$cwd = getcwd();
if ($cwd === false) {
    fwrite(STDERR, "Error: Could not get current working directory.\n");
    exit(1);
}

foreach ($initialDataFiles as $file) {
    if (is_dir($file->srcPath)) {
        $foundFiles = findFilesRecursive($file->srcPath, $file->dstPath, $file->mode);
        $allDataFiles = array_merge($allDataFiles, $foundFiles);
    } elseif (is_file($file->srcPath)) {
        if (!shouldIgnore($file->srcPath, $options)) {
             $allDataFiles[] = $file; // Keep original DataFile object
        } else {
             fwrite(STDERR, "DEBUG: Excluding explicitly listed file '{$file->srcPath}'\n"); // DEBUG
        }
    }
}

if (empty($allDataFiles) && !$options->force) {
     fwrite(STDERR, "No input files found and --no-force specified. Exiting.\n");
     exit(0);
}
if (empty($allDataFiles)) {
    fwrite(STDERR, "Error: No valid input files specified.\n");
    exit(1);
}

foreach ($allDataFiles as $file) {
    if (!$file->explicitDstPath) {
        $realSrcPath = realpath($file->srcPath);
        if ($realSrcPath === false) {
             fwrite(STDERR, "Error: Could not resolve real path for '{$file->srcPath}'.\n");
             exit(1);
        }
        if (strpos($realSrcPath, $cwd) !== 0) {
             fwrite(STDERR, "Error: Input file '{$file->srcPath}' is not within the current directory '{$cwd}'. Use src@dst syntax for files outside CWD.\n");
             exit(1);
        }
        $file->dstPath = substr($realSrcPath, strlen($cwd) + 1);
    }

    $file->dstPath = normalizePath($file->dstPath);

    if (substr($file->dstPath, -1) === '/') {
        $file->dstPath .= basename($file->srcPath);
    }

    if (strpos($file->dstPath, '/') !== 0) {
         $file->dstPath = '/' . $file->dstPath;
    }
     $file->dstPath = normalizePath($file->dstPath);

}


$seen = [];
$uniqueDataFiles = [];
foreach ($allDataFiles as $file) {
    if (!isset($seen[$file->dstPath])) {
        $uniqueDataFiles[] = $file;
        $seen[$file->dstPath] = true;
    }
}
$allDataFiles = $uniqueDataFiles;


usort($allDataFiles, function (DataFile $a, DataFile $b) {
    return strcmp($a->dstPath, $b->dstPath);
});

$currentOffset = 0;
$writeDataTarget = $dataTarget;
$tempDataFile = null;
if ($options->lz4) {
    $tempDataFile = tempnam(sys_get_temp_dir(), 'empack_lz4_');
    if ($tempDataFile === false) {
        fwrite(STDERR, "Error: Could not create temporary file for LZ4 compression.\n");
        exit(1);
    }
    $writeDataTarget = $tempDataFile;
}

$dataHandle = fopen($writeDataTarget, 'wb');
if ($dataHandle === false) {
    fwrite(STDERR, "Error: Could not open data target file '{$writeDataTarget}' for writing.\n");
    if ($tempDataFile) unlink($tempDataFile);
    exit(1);
}

$metadataFiles = [];
$totalBytesWrittenUncompressed = 0; // Track uncompressed size for reporting
foreach ($allDataFiles as $file) {
    if ($file->mode === 'preload') {
        $options->hasPreloaded = true;
        $fileContent = file_get_contents($file->srcPath);
        if ($fileContent === false) {
            fwrite(STDERR, "Error: Could not read source file '{$file->srcPath}'.\n");
            fclose($dataHandle);
            unlink($writeDataTarget); // Clean up partial/temp file
            exit(1);
        }
        $fileSize = strlen($fileContent);
        $bytesWritten = fwrite($dataHandle, $fileContent);

        if ($bytesWritten === false) {
            fwrite(STDERR, "Error: Failed to write to data file '{$writeDataTarget}' for source '{$file->srcPath}'.\n");
            fclose($dataHandle);
            unlink($writeDataTarget);
            exit(1);
        }
        if ($bytesWritten !== $fileSize) {
             fwrite(STDERR, "Warning: Incomplete write to data file '{$writeDataTarget}' for source '{$file->srcPath}'. Expected {$fileSize}, wrote {$bytesWritten}.\n");
        }

        $file->dataStart = $currentOffset;
        $file->dataEnd = $currentOffset + $fileSize;
        $totalBytesWrittenUncompressed += $fileSize; // Use actual file size for uncompressed total
        $currentOffset += $fileSize;


        $audio = (in_array(strtolower(substr($file->dstPath, -4)), ['.ogg', '.wav', '.mp3'])) ? 1 : 0;

        $metadataEntry = [
            'filename' => $file->dstPath,
            'start' => $file->dataStart,
            'end' => $file->dataEnd,
            //'audio' => $audio,
        ];

        if ($audio) {
            $metadataEntry['audio'] = 1;
        }

        $metadataFiles[] = $metadataEntry;

    } elseif ($file->mode === 'embed') {
        $options->hasEmbedded = true;
        fwrite(STDERR, "Warning: --embed mode not fully implemented yet.\n");
    }
}
fclose($dataHandle);

$nodeCheck = "typeof process === 'object' && typeof process.versions === 'object' && typeof process.versions.node === 'string'"; // Default Node.js check

if ($options->lz4 && $tempDataFile) {
    $uncompressedData = file_get_contents($tempDataFile);
    if ($uncompressedData === false) {
        fwrite(STDERR, "Error: Could not read temporary data file '{$tempDataFile}' for LZ4 compression.\n");
        unlink($tempDataFile);
        exit(1);
    }
    $originalSize = strlen($uncompressedData);
    fwrite(STDERR, "compressing package of size {$originalSize}\n"); // Mimic node script output

    try {
        $startTime = microtime(true);
        $lz4 = new LZ4();
        $compressedData = $lz4->compressPackage($uncompressedData)['data'];
        $endTime = microtime(true);
        $compressedSize = strlen($compressedData);

        if ($compressedSize === 0 && $originalSize > 0) {
             throw new \RuntimeException("LZ4 compression resulted in zero size for non-empty input.");
        }

        fwrite(STDERR, "compressed package into {$compressedSize}\n"); // Mimic node script output
        fwrite(STDERR, "compressed in " . round(($endTime - $startTime) * 1000) . " ms\n"); // Mimic node script output


        if (file_exists($dataTarget)) {
            if (!unlink($dataTarget)) {
                 fwrite(STDERR, "Warning: Could not remove existing target file '{$dataTarget}' before writing compressed data.\n");
            }
        }

        if (file_put_contents($dataTarget, $compressedData) === false) {
            throw new \RuntimeException("Failed to write compressed data to target file '{$dataTarget}'.");
        }

        $options->lz4Metadata = [
            'originalSize' => $originalSize,
            'compressedSize' => $compressedSize,
        ];

    } catch (\Exception $e) {
        fwrite(STDERR, "Error compressing data with pure PHP LZ4: " . $e->getMessage() . "\n");
        unlink($tempDataFile); // Clean up temp file
        if (file_exists($dataTarget)) unlink($dataTarget); // Clean up potentially partial target
        exit(1);
    } finally {
         unlink($tempDataFile); // Clean up temp file regardless of success/failure
    }


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


//var_dump($allDataFiles);
/*

array(2) {
  [0]=>
  object(DataFile)#8 (6) {
    ["srcPath"]=>
    string(68) "/home/kambo/workspace/Syntaxx/WebAssemblyPacker/test_dir/include.txt"
    ["dstPath"]=>
    string(21) "/test_dir/include.txt"
    ["mode"]=>
    string(7) "preload"
    ["explicitDstPath"]=>
    bool(false)
    ["dataStart"]=>
    int(0)
    ["dataEnd"]=>
    int(13)
  }
  [1]=>
  object(DataFile)#13 (6) {
    ["srcPath"]=>
    string(75) "/home/kambo/workspace/Syntaxx/WebAssemblyPacker/test_dir/subdir/another.txt"
    ["dstPath"]=>
    string(28) "/test_dir/subdir/another.txt"
    ["mode"]=>
    string(7) "preload"
    ["explicitDstPath"]=>
    bool(false)
    ["dataStart"]=>
    int(13)
    ["dataEnd"]=>
    int(26)
  }
}
*/

// I need to create this array:

/*
      Module["FS_createPath"]("/", "test_dir", true, true);
      Module["FS_createPath"]("/test_dir", "subdir", true, true);
*/

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
