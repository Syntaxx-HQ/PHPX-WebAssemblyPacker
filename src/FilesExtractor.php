<?php

namespace PHPX\WebAssemblyPacker;

class FilesExtractor {


    public function process(Options $options, string $cwd, array $initialDataFiles): array {
        $allDataFiles = [];
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

        return $allDataFiles;
    }
}
