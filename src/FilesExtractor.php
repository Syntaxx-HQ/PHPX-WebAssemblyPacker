<?php

namespace PHPX\WebAssemblyPacker;

use PHPX\WebAssemblyPacker\Infra\EventManager;

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
        return '.';
    }

    return $normalized ?: '.';
}

/**
 * Checks if a given path matches any of the exclusion patterns.
 * Uses PHP's fnmatch, similar to Python's fnmatch.
 */
function shouldIgnore(string $path, Options $options, EventManager $eventManager): bool {
    $normalizedPath = rtrim(normalizePath($path), '/');
    $basename = basename($normalizedPath);

    foreach ($options->excludePatterns as $pattern) {
        $pattern = str_replace('\\', '/', $pattern);
        
        if (fnmatch($pattern, $normalizedPath)) {
            $eventManager->debug("Excluding '{$path}' due to pattern '{$pattern}'");
            return true;
        }
        
        if (fnmatch($pattern, $basename)) {
            $eventManager->debug("Excluding '{$path}' due to basename pattern '{$pattern}'");
            return true;
        }
        
        if (strpos($pattern, '/') !== false) {
            $dirPattern = rtrim($pattern, '/') . '/';
            if (strpos($normalizedPath . '/', $dirPattern) === 0) {
                $eventManager->debug("Excluding '{$path}' because it's inside excluded directory pattern '{$pattern}'");
                return true;
            }
        }
        
        if (is_dir($path) && $normalizedPath === rtrim(normalizePath($pattern), '/')) {
            $eventManager->debug("Excluding directory '{$path}' matching pattern '{$pattern}'");
            return true;
        }
    }
    return false;
}

/**
 * Recursively finds files in a directory, similar to os.walk.
 * Returns an array of DataFile objects.
 */
function findFilesRecursive(string $srcPath, string $dstPathRoot, string $mode, Options $options, EventManager $eventManager): array {
    $files = [];
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($srcPath, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $fullSrcPath = $item->getPathname();

        if (shouldIgnore($fullSrcPath, $options, $eventManager)) {
            continue;
        }

        $relativePath = substr($fullSrcPath, strlen($srcPath) + 1);
        $currentDstPath = normalizePath($dstPathRoot . '/' . $relativePath);

        if ($item->isFile()) {
            $files[] = new DataFile($fullSrcPath, $currentDstPath, $mode, false);
        }
    }
    return $files;
}

class FilesExtractor {
    private EventManager $eventManager;

    public function __construct(EventManager $eventManager)
    {
        $this->eventManager = $eventManager;
    }

    public function process(Options $options, string $cwd, array $initialDataFiles): array {
        $allDataFiles = [];
        foreach ($initialDataFiles as $file) {
            if (is_dir($file->srcPath)) {
                $this->eventManager->fileProcessingStart($file->srcPath);
                $foundFiles = findFilesRecursive($file->srcPath, $file->dstPath, $file->mode, $options, $this->eventManager);
                $allDataFiles = array_merge($allDataFiles, $foundFiles);
                $this->eventManager->fileProcessingComplete($file->srcPath);
            } elseif (is_file($file->srcPath)) {
                if (!shouldIgnore($file->srcPath, $options, $this->eventManager)) {
                    $allDataFiles[] = $file;
                } else {
                    $this->eventManager->debug("Excluding explicitly listed file '{$file->srcPath}'");
                }
            }
        }
        
        if (empty($allDataFiles) && !$options->force) {
            $this->eventManager->warning("No input files found and --no-force specified. Exiting.");
            exit(0);
        }
        
        if (empty($allDataFiles)) {
            $this->eventManager->error("No valid input files specified.");
            exit(1);
        }
        
        foreach ($allDataFiles as $file) {
            if (!$file->explicitDstPath) {
                $realSrcPath = realpath($file->srcPath);
                if ($realSrcPath === false) {
                    $this->eventManager->error("Could not resolve real path for '{$file->srcPath}'.");
                    exit(1);
                }
                if (strpos($realSrcPath, $cwd) !== 0) {
                    $this->eventManager->error("Input file '{$file->srcPath}' is not within the current directory '{$cwd}'. Use src@dst syntax for files outside CWD.");
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

        return $allDataFiles;
    }
}
