<?php 

namespace PHPX\WebAssemblyPacker;

use Syntaxx\PHPXLZ4\LZ4;

class LZ4Compressor {
    public function compress(string $tempDataFile, string $dataTarget, Options $options): int {
        $compressedSize = 0;
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
        
        return $compressedSize;
    }
}
