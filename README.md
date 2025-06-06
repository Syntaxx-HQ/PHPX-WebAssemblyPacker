# WebAssemblyPacker

A pure PHP implementation of the Emscripten file packager, designed to package and compress files for WebAssembly applications.

## Overview

WebAssemblyPacker is a PHP port of the Emscripten `file_packager.py` script. It provides functionality to package files for WebAssembly applications, including support for preloading, embedding, compression, and caching.

## Features

- Pure PHP implementation with no external dependencies
- LZ4 compression support
- File preloading and embedding
- IndexedDB caching support
- Pattern-based file exclusion
- Customizable JavaScript output
- Node.js compatibility options

## Installation

```bash
composer require syntaxx/webassembly-packer
```

## Usage

### Basic Usage

```bash
php packer.php data.bin \
    --preload files/ \
    --js-output=output.js \
    --lz4 \
    --use-preload-cache \
    --exclude '*.tmp' \
    --no-node \
    --export-name=createModule
```

### Basic Usage As Library

```php
use Syntaxx\WebAssemblyPacker\WebAssemblyPacker;
use Syntaxx\WebAssemblyPacker\Options;
use Syntaxx\WebAssemblyPacker\Infra\EventManager;

$eventManager = new EventManager();
$packer = new WebAssemblyPacker($eventManager);
$options = new Options();

// Configure options
$options->jsOutput = 'output.js';
$options->lz4 = true;
$options->usePreloadCache = true;

// Run the packer
$packer->pack($options, ['packer.php', 'data.bin']);
```

### Options

- `--preload`: Specify files or directories to preload
- `--js-output`: Output JavaScript file path
- `--lz4`: Enable LZ4 compression
- `--use-preload-cache`: Enable IndexedDB caching
- `--exclude`: Exclude files matching pattern
- `--no-node`: Disable Node.js specific code
- `--export-name`: Custom export name for the module

## Project Structure

```
src/
├── WebAssemblyPacker.php    # Main packer class
├── Options.php             # Configuration options
├── FilesExtractor.php      # File processing
├── DataPacker.php          # Data packaging
├── LZ4Compressor.php       # LZ4 compression
├── DataFile.php            # Data file handling
├── Infra/                  # Infrastructure code
└── JS/                     # JavaScript templates
```

## Requirements

- PHP 8.1 or higher
- Composer for dependency management

## License

This project is licensed under the MIT License - see the LICENSE file for details.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Acknowledgments

- Original Emscripten file packager script
