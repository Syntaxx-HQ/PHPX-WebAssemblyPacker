<?php

namespace Syntaxx\WebAssemblyPacker;

use Syntaxx\WebAssemblyPacker\Infra\EventManager;

class Options {
    public ?string $jsOutput = null;
    public bool $force = false; // Default matches Python script
    public bool $hasPreloaded = false;
    public bool $hasEmbedded = false; // For future use
    public string $exportName = 'Module'; // Default export name
    public bool $supportNode = true; // Default support node
    public bool $usePreloadCache = false;
    public bool $lz4 = false;
    public bool $debug = false; // Debug mode flag
    public array $excludePatterns = []; // Renamed from excludedPatterns
    public ?array $lz4Metadata = null; // To store metadata from lz4-compress.mjs
    public array $initialDataFiles = [];
    public string $cwd;
    public ?string $prefixDir = null; // Directory prefix for output paths

    public function __construct(string $cwd)
    {
        $this->cwd = $cwd;
    }

    public static function fromCliArgs(int $argc, array $argv, EventManager $eventManager, string $cwd): Options {
        $leading = '';
        $options = new Options($cwd);
        for ($i = 2; $i < $argc; $i++) {
            $arg = $argv[$i];
            $eventManager->debug("Processing argument {$i}: '{$arg}'");
        
            if ($arg === '--preload') {
                $leading = 'preload';
            } elseif ($arg === '--embed') {
                $leading = 'embed';
            } elseif ($arg === '--exclude') {
                $leading = 'exclude';
                $eventManager->debug("Set leading to 'exclude'");
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
            } elseif ($arg === '--debug') {
                $options->debug = true;
                $leading = '';
            } elseif (strpos($arg, '--export-name=') === 0) {
                $options->exportName = substr($arg, strlen('--export-name='));
                $leading = '';
            } elseif (strpos($arg, '--prefix-dir=') === 0) {
                $options->prefixDir = substr($arg, strlen('--prefix-dir='));
                $leading = '';
            } elseif ($leading === 'exclude') {
                $pattern = trim($arg, "'\"");
                $eventManager->debug("Adding exclude pattern: '{$pattern}'");
                $options->excludePatterns[] = $pattern;
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
                    $eventManager->error("Input path '{$srcPath}' does not exist.");
                    exit(1);
                }
        
                $options->initialDataFiles[] = new DataFile($srcPath, $dstPath, $mode, $explicitDstPath);
            } else {
                $eventManager->warning("Unknown parameter: {$arg}");
                $leading = '';
            }
        }

        return $options;
    }
}
