<?php

namespace PHPX\WebAssemblyPacker;

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
    public array $initialDataFiles = [];

    public static function fromCliArgs(int $argc, array $argv): Options {
        $leading = '';
        $options = new Options();
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
        
                $options->initialDataFiles[] = new DataFile($srcPath, $dstPath, $mode, $explicitDstPath);
            } else {
                fwrite(STDERR, "Unknown parameter: {$arg}\n");
                $leading = ''; // Reset leading if unknown param encountered
            }
        }

        return $options;
    }
}
