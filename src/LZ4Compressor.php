<?php 

namespace Syntaxx\WebAssemblyPacker;

use Syntaxx\LZ4\LZ4;
use Syntaxx\WebAssemblyPacker\Infra\EventManager;

class LZ4Compressor {
    private EventManager $eventManager;

    public function __construct(EventManager $eventManager)
    {
        $this->eventManager = $eventManager;
    }

    public function compress(string $tempDataFile, string $dataTarget, Options $options): int {
        $compressedSize = 0;
        $uncompressedData = file_get_contents($tempDataFile);
        if ($uncompressedData === false) {
            $this->eventManager->error("Could not read temporary data file '{$tempDataFile}' for LZ4 compression.");
            unlink($tempDataFile);
            exit(1);
        }
        $originalSize = strlen($uncompressedData);
        $this->eventManager->info("Compressing package of size {$originalSize}");
    
        try {
            $startTime = microtime(true);
            $this->eventManager->compressionStart($originalSize);
            
            $lz4 = new LZ4();
            $compressedData = $lz4->compressPackage($uncompressedData)['data'];
            $endTime = microtime(true);
            $compressedSize = strlen($compressedData);
            $duration = $endTime - $startTime;
    
            if ($compressedSize === 0 && $originalSize > 0) {
                throw new \RuntimeException("LZ4 compression resulted in zero size for non-empty input.");
            }
    
            $this->eventManager->compressionComplete($originalSize, $compressedSize, $duration);
    
            if (file_exists($dataTarget)) {
                if (!unlink($dataTarget)) {
                    $this->eventManager->warning("Could not remove existing target file '{$dataTarget}' before writing compressed data.");
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
            $this->eventManager->compressionError($originalSize, $e->getMessage());
            unlink($tempDataFile);
            if (file_exists($dataTarget)) unlink($dataTarget);
            exit(1);
        } finally {
            unlink($tempDataFile);
        }  
        
        return $compressedSize;
    }
}
