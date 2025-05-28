<?php

namespace Syntaxx\WebAssemblyPacker;

use Syntaxx\WebAssemblyPacker\Infra\EventManager;

class DataPacker {
    private EventManager $eventManager;

    public function __construct(EventManager $eventManager)
    {
        $this->eventManager = $eventManager;
    }

    public function pack(Options $options, string $dataTarget, array $allDataFiles): array {
        $currentOffset = 0;
        $writeDataTarget = $dataTarget;
        $tempDataFile = null;
        if ($options->lz4) {
            $tempDataFile = tempnam(sys_get_temp_dir(), 'empack_lz4_');
            if ($tempDataFile === false) {
                $this->eventManager->error("Could not create temporary file for LZ4 compression.");
                exit(1);
            }
            $writeDataTarget = $tempDataFile;
        }
        
        $dataHandle = fopen($writeDataTarget, 'wb');
        if ($dataHandle === false) {
            $this->eventManager->error("Could not open data target file '{$writeDataTarget}' for writing.");
            if ($tempDataFile) unlink($tempDataFile);
            exit(1);
        }
        
        $metadataFiles = [];
        $totalBytesWrittenUncompressed = 0;
        foreach ($allDataFiles as $file) {
            if ($file->mode === 'preload') {
                $options->hasPreloaded = true;
                $this->eventManager->fileProcessingStart($file->srcPath);
                
                $fileContent = file_get_contents($file->srcPath);
                if ($fileContent === false) {
                    $this->eventManager->fileProcessingError($file->srcPath, "Could not read source file");
                    fclose($dataHandle);
                    unlink($writeDataTarget);
                    exit(1);
                }
                $fileSize = strlen($fileContent);
                $bytesWritten = fwrite($dataHandle, $fileContent);
        
                if ($bytesWritten === false) {
                    $this->eventManager->fileProcessingError($file->srcPath, "Failed to write to data file");
                    fclose($dataHandle);
                    unlink($writeDataTarget);
                    exit(1);
                }
                if ($bytesWritten !== $fileSize) {
                    $this->eventManager->warning("Incomplete write to data file '{$writeDataTarget}' for source '{$file->srcPath}'. Expected {$fileSize}, wrote {$bytesWritten}.");
                }
        
                $file->dataStart = $currentOffset;
                $file->dataEnd = $currentOffset + $fileSize;
                $totalBytesWrittenUncompressed += $fileSize;
                $currentOffset += $fileSize;
        
                $audio = (in_array(strtolower(substr($file->dstPath, -4)), ['.ogg', '.wav', '.mp3'])) ? 1 : 0;
        
                $metadataEntry = [
                    'filename' => $options->prefixDir ? '/'.$options->prefixDir . $file->dstPath : $file->dstPath,
                    'start' => $file->dataStart,
                    'end' => $file->dataEnd,
                ];
        
                if ($audio) {
                    $metadataEntry['audio'] = 1;
                }
        
                $metadataFiles[] = $metadataEntry;
                $this->eventManager->fileProcessingComplete($file->srcPath);
        
            } elseif ($file->mode === 'embed') {
                $options->hasEmbedded = true;
                $this->eventManager->warning("--embed mode not fully implemented yet.");
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
