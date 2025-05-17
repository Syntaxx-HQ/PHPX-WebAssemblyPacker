<?php

namespace Syntaxx\WebAssemblyPacker\JS;

use Syntaxx\WebAssemblyPacker\Options;

class JSTemplates {
    public function fillTemplate(
        Options $options,
        string $dataTarget,
        array $allDataFiles,
        array $metadataFiles,
        int $totalBytesWrittenUncompressed,
        ?int $compressedSize = null
    ) : string {
        $createPaths = [];
        foreach ($allDataFiles as $file) {
            $path = $file->dstPath;
            $segments = explode('/', trim($path, '/'));
            $current = '';
            for ($i = 0; $i < count($segments) - 1; $i++) {
                $parent = $current === '' ? '/' : '/' . $current;
                $dir = $segments[$i];
                $fullPath = $parent . '/' . $dir;
                if (!isset($createdPaths[$fullPath])) {
                    $createPaths[] = 'Module["FS_createPath"]("' . $parent . '", "' . $dir . '", true, true);' . PHP_EOL;
                    $createdPaths[$fullPath] = true;
                }
                $current .= ($current ? '/' : '') . $dir;
            }
        }

        $data = file_get_contents($dataTarget);
        $packageUuid = 'sha256-' . hash('sha256', $data);

        if ($options->lz4) {
            $metadataArray = ['files' => $metadataFiles, 'remote_package_size' => $compressedSize, 'package_uuid' => $packageUuid];
            $metadataJson  = json_encode($metadataArray, JSON_UNESCAPED_SLASHES);

            $jsCode = strtr(
                file_get_contents(__DIR__ . '/template/compress.data.js'),
                [
                    '#module_name#' => $options->exportName,
                    '#package_name#' => $dataTarget,
                    '#remote_package_base#' => basename($dataTarget),
                    '#data_file#' => 'datafile_build/'.basename($dataTarget),
                    '#package_content#' => $metadataJson,
                    '#create_paths#' => implode('', $createPaths),
                    '#uncompresed_size#' => $totalBytesWrittenUncompressed,
                ]
            );
        } else {
            $metadataArray = ['files' => $metadataFiles, 'remote_package_size' => $totalBytesWrittenUncompressed, 'package_uuid' => $packageUuid];
            $metadataJson  = json_encode($metadataArray, JSON_UNESCAPED_SLASHES);

            $jsCode = strtr(
                file_get_contents(__DIR__ . '/template/no-compress.data.js'),
                [
                    '#module_name#' => $options->exportName,
                    '#package_name#' => $dataTarget,
                    '#remote_package_base#' => basename($dataTarget),
                    '#data_file#' => 'datafile_build/'.basename($dataTarget),
                    '#package_content#' => $metadataJson,
                    '#create_paths#' => implode('', $createPaths),
                ]
            );
        }   
        
        return $jsCode;
    }
}
