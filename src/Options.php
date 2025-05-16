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
}
