<?php

namespace PHPX\WebAssemblyPacker;

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
function findFilesRecursive(string $srcPath, string $dstPathRoot, string $mode, Options $options): array {

    $files = [];
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($srcPath, \RecursiveDirectoryIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST
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



class FilesExtractor {


    public function process(Options $options, string $cwd, array $initialDataFiles): array {
        $allDataFiles = [];
        foreach ($initialDataFiles as $file) {
            if (is_dir($file->srcPath)) {
                $foundFiles = findFilesRecursive($file->srcPath, $file->dstPath, $file->mode, $options);
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

        return $allDataFiles;
    }
}
