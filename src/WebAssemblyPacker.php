<?php

namespace Syntaxx\WebAssemblyPacker;

use Syntaxx\WebAssemblyPacker\Infra\EventManager;
use Syntaxx\WebAssemblyPacker\Options;
use Syntaxx\WebAssemblyPacker\FilesExtractor;
use Syntaxx\WebAssemblyPacker\DataPacker;
use Syntaxx\WebAssemblyPacker\LZ4Compressor;
use Syntaxx\WebAssemblyPacker\JS\JSTemplates;

class WebAssemblyPacker {
    private EventManager $eventManager;

    public function __construct(EventManager $eventManager) {
        $this->eventManager = $eventManager;
    }

    public function pack(Options $options, array $argv): void {
        $dataTarget = $argv[1];
        $initialDataFiles = $options->initialDataFiles;
        
        $filesExtractor = new FilesExtractor($this->eventManager);
        $allDataFiles = $filesExtractor->process($options, $options->cwd, $initialDataFiles);
        
        $dataPacker = new DataPacker($this->eventManager);
        [$metadataFiles, $totalBytesWrittenUncompressed, $tempDataFile] = $dataPacker->pack($options, $dataTarget, $allDataFiles);
        
        $nodeCheck = "typeof process === 'object' && typeof process.versions === 'object' && typeof process.versions.node === 'string'"; // Default Node.js check
        
        $compressedSize = null;
        if ($options->lz4 && $tempDataFile) {
            $lz4Compressor = new LZ4Compressor($this->eventManager);
            $compressedSize = $lz4Compressor->compress($tempDataFile, $dataTarget, $options);
        } elseif (!$options->lz4) {
        }
        
        if (!$options->hasPreloaded && !$options->hasEmbedded) {
            $this->eventManager->warning("Nothing to preload or embed.");
            if (!$options->force) exit(0);
        }
        
        $this->eventManager->info("Processed " . count($allDataFiles) . " files.");
        if (file_exists($dataTarget)) {
            $size = filesize($dataTarget);
            $status = $options->lz4 ? " (compressed)" : "";
            $this->eventManager->info("Data file created: {$dataTarget} ({$size} bytes{$status})");
        } else {
            $this->eventManager->error("Data file NOT created: {$dataTarget}");
        }
        
        $jsTemplates = new JSTemplates($this->eventManager);
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
            $this->eventManager->info("JS output created: {$options->jsOutput}");
        } elseif ($options->jsOutput) {
            $this->eventManager->error("JS output NOT created: {$options->jsOutput}");
        }
    }
}
