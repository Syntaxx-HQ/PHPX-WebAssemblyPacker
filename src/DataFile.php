<?php

namespace Syntaxx\WebAssemblyPacker;

class DataFile {
    public string $srcPath;
    public string $dstPath;
    public string $mode; // 'preload' or 'embed'
    public bool $explicitDstPath;
    public int $dataStart = 0;
    public int $dataEnd = 0;
    public function __construct(string $srcPath, string $dstPath, string $mode, bool $explicitDstPath) {
        $this->srcPath = $srcPath;
        $this->dstPath = $dstPath;
        $this->mode = $mode;
        $this->explicitDstPath = $explicitDstPath;
    }
}
