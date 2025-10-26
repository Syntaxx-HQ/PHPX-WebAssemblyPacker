<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/lz4_php.php'; // Include the pure PHP LZ4 implementation

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

        $metadataEntry = [
            'filename' => $file->dstPath,
            'start' => $file->dataStart,
            'end' => $file->dataEnd,
            'audio' => (in_array(strtolower(substr($file->dstPath, -4)), ['.ogg', '.wav', '.mp3'])) ? 1 : 0,
        ];
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
        $compressedData = LZ4_PHP::compress($uncompressedData);
        $endTime = microtime(true);
        $compressedSize = strlen($compressedData);

        if ($compressedSize === 0 && $originalSize > 0) {
             throw new \RuntimeException("LZ4_PHP::compress resulted in zero size for non-empty input.");
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

$jsCode = "";

$jsCode .= "var Module = typeof {$options->exportName} != 'undefined' ? {$options->exportName} : {};\n\n";
$jsCode .= "Module['expectedDataFileDownloads'] ??= 0;\n";
$jsCode .= "Module['expectedDataFileDownloads']++;\n";
$jsCode .= "(() => {\n";
$jsCode .= "// Do not attempt to redownload the virtual filesystem data when in a pthread or a Wasm Worker context.\n";
$jsCode .= "  var isPthread = typeof ENVIRONMENT_IS_PTHREAD != 'undefined' && ENVIRONMENT_IS_PTHREAD;\n";
$jsCode .= "  var isWasmWorker = typeof ENVIRONMENT_IS_WASM_WORKER != 'undefined' && ENVIRONMENT_IS_WASM_WORKER;\n";
$jsCode .= "  if (isPthread || isWasmWorker) return;\n";

if ($options->supportNode) {
    $nodeCheck = "var isNode = typeof process === 'object' && typeof process.versions === 'object' && typeof process.versions.node === 'string';\n";
}

$jsCode .= "  function loadPackage(metadata) {\n";

$package_name = $writeDataTarget;
$remote_package_size = filesize($writeDataTarget);
$remote_package_name = basename($writeDataTarget);

$jsCode .= <<<JS
  var PACKAGE_PATH = '';
  if (typeof window === 'object') {
    PACKAGE_PATH = window['encodeURIComponent'](window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/')) + '/');
  } else if (typeof process === 'undefined' && typeof location !== 'undefined') {
    // web worker
    PACKAGE_PATH = encodeURIComponent(location.pathname.substring(0, location.pathname.lastIndexOf('/')) + '/');
  }
  var PACKAGE_NAME = '{$writeDataTarget}';
  var REMOTE_PACKAGE_BASE = '{$remote_package_name}';
  var REMOTE_PACKAGE_NAME = Module['locateFile'] ? Module['locateFile'](REMOTE_PACKAGE_BASE, '') : REMOTE_PACKAGE_BASE;

JS;

$metadata['remote_package_size'] = $remote_package_size;
$jsCode .= "var REMOTE_PACKAGE_SIZE = metadata['remote_package_size'];\n\n";

$jsCode .= <<<JS
  function fetchRemotePackage(packageName, packageSize, callback, errback) {
    {$nodeSupportCode}
    Module['dataFileDownloads'] ??= {};
    fetch(packageName)
      .catch((cause) => Promise.reject(new Error(`Network Error: \${packageName}`, {cause}))) // If fetch fails, rewrite the error to include the failing URL & the cause.
      .then((response) => {
        if (!response.ok) {
          return Promise.reject(new Error(`\${response.status}: \${response.url}`));
        }

        if (!response.body && response.arrayBuffer) { // If we're using the polyfill, readers won't be available...
          return response.arrayBuffer().then(callback);
        }

        const reader = response.body.getReader();
        const iterate = () => reader.read().then(handleChunk).catch((cause) => {
          return Promise.reject(new Error(`Unexpected error while handling : \${response.url} \${cause}`, {cause}));
        });

        const chunks = [];
        const headers = response.headers;
        const total = Number(headers.get('Content-Length') ?? packageSize);
        let loaded = 0;

        const handleChunk = ({done, value}) => {
          if (!done) {
            chunks.push(value);
            loaded += value.length;
            Module['dataFileDownloads'][packageName] = {loaded, total};

            let totalLoaded = 0;
            let totalSize = 0;

            for (const download of Object.values(Module['dataFileDownloads'])) {
              totalLoaded += download.loaded;
              totalSize += download.total;
            }

            Module['setStatus']?.(`Downloading data... (\${totalLoaded}/\${totalSize})`);
            return iterate();
          } else {
            const packageData = new Uint8Array(chunks.map((c) => c.length).reduce((a, b) => a + b, 0));
            let offset = 0;
            for (const chunk of chunks) {
              packageData.set(chunk, offset);
              offset += chunk.length;
            }
            callback(packageData.buffer);
          }
        };

        Module['setStatus']?.('Downloading data...');
        return iterate();
      });
  };

  function handleError(error) {
    console.error('package error:', error);
  };
JS;

$jsCode .= <<<JS
  function processPackageData(arrayBuffer) {
    assert(arrayBuffer, 'Loading data file failed.');
    assert(arrayBuffer.constructor.name === ArrayBuffer.name, 'bad input to processPackageData');
    var byteArray = new Uint8Array(arrayBuffer);
    var curr;
    {$useData}
  };
  Module['addRunDependency']('datafile_{$dataTarget}');
JS;


$jsCode .= "    function assert(check, msg) {\n";
$jsCode .= "      if (!check) throw msg + new Error().stack;\n";
$jsCode .= "    }\n";

$partialDirs = [];
$createPathCalls = "";
foreach ($allDataFiles as $file) {
    $dirname = dirname($file->dstPath);
    $dirname = ltrim($dirname, '/'); // Remove leading / for splitting
    if ($dirname !== '' && $dirname !== '.') {
        $parts = explode('/', $dirname);
        $currentPath = '';
        for ($i = 0; $i < count($parts); $i++) {
            $parentPath = json_encode('/' . $currentPath);
            $part = json_encode($parts[$i]);
            $partial = $currentPath . ($currentPath ? '/' : '') . $parts[$i];
             if (!isset($partialDirs[$partial])) {
                 $createPathCalls .= "    Module['FS_createPath']({$parentPath}, {$part}, true, true);\n";
                 $partialDirs[$partial] = true;
             }
             $currentPath = $partial;
        }
    }
}

$jsCode .= $createPathCalls;

if ($options->hasPreloaded) {
    /*$createData = "";
    if ($options->usePreloadCache) {
        $createData .= "        // Check if the file exists in the cache\n";
        $createData .= "        var cacheKey = 'FILE_DATA_' + this.name;\n";
        $createData .= "        var cachedData = typeof {$options->exportName} !== 'undefined' && {$options->exportName}['GL'] ? {$options->exportName}['GL'].loadCache(cacheKey) : null;\n"; // Assuming GL context for cache
        $createData .= "        if (cachedData) {\n";
        $createData .= "          {$options->exportName}['FS_createDataFile'](this.name, null, cachedData, true, true, true);\n";
        $createData .= "          {$options->exportName}['removeRunDependency']('fp ' + that.name);\n";
        $createData .= "        } else {\n";
        $createData .= "          // Data not in cache, create file and potentially store in cache\n";
        $createData .= "          {$options->exportName}['FS_createDataFile'](this.name, null, byteArray, true, true, true);\n";
        $createData .= "          if (typeof {$options->exportName} !== 'undefined' && {$options->exportName}['GL']) {$options->exportName}['GL'].storeCache(cacheKey, byteArray);\n"; // Store if cache available
        $createData .= "          {$options->exportName}['removeRunDependency']('fp ' + that.name);\n";
        $createData .= "        }\n";
    } else {
        $createData .= "        // Not using cache, always create the file\n";
        $createData .= "        {$options->exportName}['FS_createDataFile'](this.name, null, byteArray, true, true, true);\n";
        $createData .= "        {$options->exportName}['removeRunDependency']('fp ' + that.name);\n";
    }*/


    // Convert into PHP:
    $createPreloaded = '
          Module[\'FS_createPreloadedFile\'](this.name, null, byteArray, true, true,
            () => Module[\'removeRunDependency\'](`fp ${that.name}`),
            () => err(`Preloading file ${that.name} failed`),
            false, true); // canOwn this data in the filesystem, it is a slide into the heap that will never change\n'."\n";
    $createData = '// canOwn this data in the filesystem, it is a slide into the heap that will never change
          Module[\'FS_createDataFile\'](this.name, null, byteArray, true, true, true);
          Module[\'removeRunDependency\'](`fp ${that.name}`);';


    $createData = rtrim($createData); // Remove trailing newline if any

    $jsCode .= "    /** @constructor */\n";
    $jsCode .= "    function DataRequest(start, end, audio) {\n";
    $jsCode .= "      this.start = start;\n";
    $jsCode .= "      this.end = end;\n";
    $jsCode .= "      this.audio = audio;\n";
    $jsCode .= "    }\n";
    $jsCode .= "    DataRequest.prototype = {\n";
    $jsCode .= "      requests: {},\n";
    $jsCode .= "      open: function(mode, name) {\n";
    $jsCode .= "        this.name = name;\n";
    $jsCode .= "        this.requests[name] = this;\n";
    $jsCode .= "        {$options->exportName}['addRunDependency']('fp ' + this.name);\n"; // Use string concat
    $jsCode .= "      },\n";
    $jsCode .= "      send: function() {},\n";
    $jsCode .= "      onload: function() {\n";
    $jsCode .= "        var byteArray = this.byteArray.subarray(this.start, this.end);\n";
    $jsCode .= "        this.finish(byteArray);\n";
    $jsCode .= "      },\n";
    $jsCode .= "      finish: function(byteArray) {\n";
    $jsCode .= "        var that = this;\n";
    $jsCode .= "        " . $createData . "\n"; // Inject the createData logic
    $jsCode .= "        this.requests[this.name] = null;\n";
    $jsCode .= "      }\n";
    $jsCode .= "    };\n\n";

    $jsCode .= "    var files = metadata['files'];\n";
    $jsCode .= "    for (var i = 0; i < files.length; ++i) {\n";
    $jsCode .= "      new DataRequest(files[i]['start'], files[i]['end'], files[i]['audio'] || 0).open('GET', files[i]['filename']);\n";
    $jsCode .= "    }\n";

    $jsCode .= $createPreloaded;

    $escapedDataTarget = json_encode($dataTarget); // Use the actual data target name
    $packageSize = $currentOffset; // Total size of the concatenated data

    $jsCode .= "    function fetchRemotePackage(packageName, packageSize, callback, errback) {\n";
    $jsCode .= "      var xhr = new XMLHttpRequest();\n";
    $jsCode .= "      xhr.open('GET', packageName, true);\n";
    $jsCode .= "      xhr.responseType = 'arraybuffer';\n";
    $jsCode .= "      xhr.onprogress = function(event) {\n";
    $jsCode .= "        var url = packageName;\n";
    $jsCode .= "        var size = packageSize;\n";
    $jsCode .= "        if (event.total) size = event.total;\n";
    $jsCode .= "        if (event.loaded) {\n";
    $jsCode .= "          if (!xhr.addedTotal) {\n";
    $jsCode .= "            xhr.addedTotal = true;\n";
    $jsCode .= "            if (!{$options->exportName}.dataFileDownloads) {$options->exportName}.dataFileDownloads = {};\n";
    $jsCode .= "            {$options->exportName}.dataFileDownloads[url] = {\n";
    $jsCode .= "              loaded: event.loaded,\n";
    $jsCode .= "              total: size\n"; // Corrected concatenation and indentation
    $jsCode .= "            };\n"; // Corrected concatenation, added semicolon
    $jsCode .= "          }\n"; // Close the if (!xhr.addedTotal) block inside JS string
    $jsCode .= "          {$options->exportName}.dataFileDownloads[url].loaded = event.loaded;\n";
    $jsCode .= "          var total = 0;\n";
    $jsCode .= "          var loaded = 0;\n";
    $jsCode .= "          var num = 0;\n";
    $jsCode .= "          for (var download in {$options->exportName}.dataFileDownloads) {\n";
    $jsCode .= "            var data = {$options->exportName}.dataFileDownloads[download];\n";
    $jsCode .= "            total += data.total;\n";
    $jsCode .= "            loaded += data.loaded;\n";
    $jsCode .= "            num++;\n";
    $jsCode .= "          }\n";
    $jsCode .= "          total = Math.ceil(total * {$options->exportName}.expectedDataFileDownloads / num);\n";
    $jsCode .= "          if ({$options->exportName}.setStatus) {$options->exportName}.setStatus('Downloading data... (' + loaded + '/' + total + ')');\n";
    $jsCode .= "        } else if (!total) {\n"; // Close the if (event.loaded) block and start else if
    $jsCode .= "          if ({$options->exportName}.setStatus) {$options->exportName}.setStatus('Downloading data...');\n";
    $jsCode .= "        }\n"; // Close the else if block
    $jsCode .= "      };\n"; // Close the onprogress function assignment
    $jsCode .= "      xhr.onerror = function(event) {\n";
    $jsCode .= "        throw new Error(\"NetworkError for: \" + packageName);\n"; // Escaped double quotes
    $jsCode .= "      };\n"; // Added semicolon
    $jsCode .= "      xhr.onload = function(event) {\n";
    $jsCode .= "        if (xhr.status == 200 || xhr.status == 304 || xhr.status == 206 || (xhr.status == 0 && xhr.response)) { // file URLs can return 0\n";
    $jsCode .= "          var packageData = xhr.response;\n";
    $jsCode .= "          callback(new Uint8Array(packageData));\n";
    $jsCode .= "        } else {\n";
    $jsCode .= "          throw new Error(xhr.statusText + \" : \" + xhr.responseURL);\n"; // Escaped double quotes
    $jsCode .= "        }\n";
    $jsCode .= "      };\n"; // Added semicolon
    $jsCode .= "      xhr.send(null);\n";
    $jsCode .= "    };\n"; // Close fetchRemotePackage function
    $jsCode .= "    function handleError(error) {\n";
    $jsCode .= "      console.error('package error:', error);\n";
    $jsCode .= "    };\n";
    $jsCode .= "    var fetchedCallback = null;\n";
    $jsCode .= "    var fetchedErrback = null;\n";
    $jsCode .= "    var remotePackageName = {$escapedDataTarget};\n"; // Use the actual data target name
    $jsCode .= "    var remotePackageSize = {$packageSize};\n"; // Use the calculated package size
    $jsCode .= "    {$options->exportName}['addRunDependency']('datafile_' + remotePackageName);\n";
    $jsCode .= "    if (typeof {$options->exportName}.locateFile === 'function') {\n";
    $jsCode .= "      remotePackageName = {$options->exportName}.locateFile(remotePackageName, '');\n";
    $jsCode .= "    }\n";
    $jsCode .= "    fetchRemotePackage(remotePackageName, remotePackageSize, (byteArray) => {\n"; // Start fetchRemotePackage call
    $jsCode .= '      var useData = `' . "\n"; // Start template literal
    $jsCode .= '          DataRequest.prototype.byteArray = byteArray;' . "\n";
    $jsCode .= '          var files = metadata[\'files\'];' . "\n"; // Escaped single quotes
    $jsCode .= '          for (var i = 0; i < files.length; ++i) {' . "\n";
    $jsCode .= '            DataRequest.prototype.requests[files[i].filename].onload();' . "\n";
    $jsCode .= '          }' . "\n";
    $jsCode .= "          {$options->exportName}['removeRunDependency']('datafile_' + remotePackageName);" . "\n"; // Back to double quotes for interpolation
    $jsCode .= '      `;' . "\n"; // End template literal
    $jsCode .= "      if (metadata['LZ4']) {\n";
    $jsCode .= "        if (typeof LZ4 === 'undefined') {\n";
    $jsCode .= "           console.error(\"LZ4 decoder not found. Make sure lz4.js is included.\");\n"; // Escaped quotes
    $jsCode .= "           throw new Error(\"LZ4 decoder missing\");\n"; // Escaped quotes
    $jsCode .= "        }\n";
    $jsCode .= "        console.log(\"Decompressing \" + byteArray.length + \" bytes of LZ4 data\");\n"; // Escaped quotes
    $jsCode .= "        try {\n";
    $jsCode .= "          var lz4Metadata = metadata; // LZ4 metadata is merged into the main metadata\n";
    $jsCode .= "          var decompressedSize = lz4Metadata['originalSize'];\n";
    $jsCode .= "          var lz4 = new LZ4();\n";
    $jsCode .= "          var decompressedData = lz4.decompress(byteArray, decompressedSize);\n";
    $jsCode .= "          byteArray = decompressedData; // Use the decompressed data\n";
    $jsCode .= "          console.log(\"Decompressed data size: \" + byteArray.length);\n"; // Escaped quotes
    $jsCode .= "        } catch (e) {\n";
    $jsCode .= "          console.error('LZ4 decompression failed:', e);\n";
    $jsCode .= "          throw new Error('Failed to decompress LZ4 data: ' + (e.message || e));\n";
    $jsCode .= "        }\n";
    $jsCode .= "      }\n"; // Close if (metadata['LZ4'])
    $jsCode .= "      eval(useData); // Use eval to execute the code string\n";
    $jsCode .= "    }, (err) => {\n"; // Close success callback, start error callback
    $jsCode .= "      throw err;\n";
    $jsCode .= "    });\n"; // Close fetchRemotePackage call

} // End of if ($options->hasPreloaded)

if ($options->hasEmbedded) {
     foreach ($allDataFiles as $idx => $file) {
         if ($file->mode === 'embed') {
             $b64Data = base64_encode(file_get_contents($file->srcPath));
             $dirname = json_encode(dirname($file->dstPath));
             $basename = json_encode(basename($file->dstPath));
             $jsCode .= "    var fileData{$idx} = '{$b64Data}';\n";
             $jsCode .= "    {$options->exportName}['FS_createDataFile']({$dirname}, {$basename}, typeof Buffer === 'function' ? Buffer.from(fileData{$idx}, 'base64') : atob(fileData{$idx}), true, true, true);\n";
         }
     }
}

$jsCode .= "  }\n"; // End loadPackage function

$metadataArray = ['files' => $metadataFiles];
if ($options->lz4) {
    $metadataArray['LZ4'] = true; // Add the LZ4 flag itself
    if ($options->lz4Metadata !== null) {
        $metadataArray = array_merge($metadataArray, $options->lz4Metadata);
    } else {
        fwrite(STDERR, "Warning: --lz4 specified but no LZ4 metadata was generated (compression script might have failed silently or metadata was not captured).\n");
    }

}
$metadataJson = json_encode($metadataArray, JSON_UNESCAPED_SLASHES);
$jsCode .= "  loadPackage({$metadataJson});\n";


$jsCode .= "})();\n"; // End IIFE



if ($options->jsOutput === null) {
    echo $jsCode;
} else {
    $write = true;
    if (file_exists($options->jsOutput)) {
        $oldContent = file_get_contents($options->jsOutput);
        if ($oldContent === $jsCode) {
            $write = false;
        }
    }
    if ($write) {
        if (file_put_contents($options->jsOutput, $jsCode) === false) {
             fwrite(STDERR, "Error: Could not write JS output to '{$options->jsOutput}'.\n");
        }
    }
}


echo "PHP File Packager: Processed " . count($allDataFiles) . " files.\n";
if (file_exists($dataTarget)) {
    $size = filesize($dataTarget);
    $status = $options->lz4 ? " (compressed)" : "";
    echo "Data file created: {$dataTarget} ({$size} bytes{$status})\n";
} else {
     echo "Data file NOT created: {$dataTarget}\n"; // Indicate if data file wasn't created
}
if ($options->jsOutput && file_exists($options->jsOutput)) {
    echo "JS output created: {$options->jsOutput}\n";
} elseif ($options->jsOutput) {
     echo "JS output NOT created: {$options->jsOutput}\n"; // Indicate if JS file wasn't created
}
