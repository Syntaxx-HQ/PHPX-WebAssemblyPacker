<?php

namespace PHPX\WebAssemblyPacker;

class DataPacker {
    public function pack(Options $options, string $dataTarget, array $allDataFiles): array {
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
        
        
                $audio = (in_array(strtolower(substr($file->dstPath, -4)), ['.ogg', '.wav', '.mp3'])) ? 1 : 0;
        
                $metadataEntry = [
                    'filename' => $file->dstPath,
                    'start' => $file->dataStart,
                    'end' => $file->dataEnd,
                    //'audio' => $audio,
                ];
        
                if ($audio) {
                    $metadataEntry['audio'] = 1;
                }
        
                $metadataFiles[] = $metadataEntry;
        
            } elseif ($file->mode === 'embed') {
                $options->hasEmbedded = true;
                fwrite(STDERR, "Warning: --embed mode not fully implemented yet.\n");
            }
        }
        fclose($dataHandle);

        return [
            $metadataFiles,
            $totalBytesWrittenUncompressed,
            $tempDataFile,
        ];
    }
}
