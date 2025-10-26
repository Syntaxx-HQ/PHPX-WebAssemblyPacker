# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Module Overview

WebAssemblyPacker is a pure PHP implementation of Emscripten's file packaging system, designed to bundle PHP applications and assets for WebAssembly deployment. It enables efficient loading of files in the browser by creating preload packages with optional LZ4 compression and sophisticated caching strategies. This module is crucial for deploying PHPX applications to the web.

## Core Architecture

### Main Components

#### `packer.php` - CLI Entry Point
- Command-line interface matching Emscripten's file_packager.py
- Argument parsing and validation
- Orchestrates the packing process
- Generates both data files and JavaScript loaders

#### `FilePacker.php` - Core Packing Logic
- File discovery and metadata collection
- Binary data package generation
- Metadata structure creation
- Event-driven architecture for extensibility

#### `JavaScriptLoader.php` - JS Generation
- Template-based JavaScript output
- Compressed and uncompressed variants
- IndexedDB caching integration
- Module initialization code

#### `FileMetadata.php` - File Information
- Stores file paths, sizes, and offsets
- Handles path normalization
- Manages file attributes
- Serialization for JS output

#### `LZ4Compressor.php` - Compression
- Pure PHP LZ4 implementation
- Interfaces with PHPX-lz4 module
- Streaming compression support
- Optimal compression ratios

## Common Development Commands

```bash
# Basic file packing
php packer.php output.data --preload files/

# Pack with LZ4 compression
php packer.php output.data --preload files/ --lz4

# Generate JavaScript loader
php packer.php output.data --preload files/ --js-output=loader.js

# Use IndexedDB caching
php packer.php output.data --preload files/ --js-output=loader.js --use-preload-cache

# Exclude specific patterns
php packer.php output.data --preload files/ --exclude='*.tmp' --exclude='cache/*'

# Set custom data file name
php packer.php output.data --preload files/ --js-output=loader.js --data-target=myapp.data

# Development build (no compression, verbose output)
php packer.php output.data --preload files/ --js-output=loader.js --no-lz4 -v
```

## File Packing Process

### 1. File Discovery
```php
// Recursively find all files in preload directories
$files = [];
foreach ($preloadDirs as $dir) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir)
    );
    foreach ($iterator as $file) {
        if (!$this->isExcluded($file)) {
            $files[] = $file;
        }
    }
}
```

### 2. Metadata Generation
```php
$metadata = [
    'files' => [
        [
            'filename' => '/virtual/path/file.php',
            'start' => 0,
            'end' => 1024,
            'audio' => false
        ]
    ],
    'remote_package_size' => 1024,
    'package_uuid' => sha256($content)
];
```

### 3. Binary Package Creation
- Files concatenated sequentially
- Offset tracking for each file
- Optional LZ4 compression applied
- Output written as binary stream

### 4. JavaScript Loader Generation
- Metadata embedded in JavaScript
- Fetch API for data loading
- IndexedDB caching logic
- Module system integration

## LZ4 Compression Implementation

### Integration with PHPX-lz4
```php
use Kambo\PHPX\LZ4\MiniLZ4;

class LZ4Compressor {
    public function compress($data) {
        $lz4 = new MiniLZ4();
        return $lz4->compress($data);
    }
    
    public function decompress($data) {
        $lz4 = new MiniLZ4();
        return $lz4->decompress($data);
    }
}
```

### Compression Strategy
- Block-based compression for streaming
- Optimal for text-heavy PHP files
- ~60-70% compression ratio typical
- Fast decompression in browser

## JavaScript Output Templates

### Uncompressed Template
```javascript
var Module = typeof Module !== 'undefined' ? Module : {};

if (!Module.expectedDataFileDownloads) {
  Module.expectedDataFileDownloads = 0;
}
Module.expectedDataFileDownloads++;

(function() {
  var loadPackage = function(metadata) {
    // File system setup
    // Data fetching
    // File registration
  };
  
  loadPackage({METADATA});
})();
```

### Compressed Template (with LZ4)
```javascript
// Includes MiniLZ4 decompressor
// Decompresses data before file system registration
// Handles streaming decompression
```

## Caching Strategies

### IndexedDB Cache Implementation
```javascript
var PACKAGE_NAME = 'package.data';
var PACKAGE_UUID = 'abc123...';
var INDEX_DB_NAME = 'EM_PRELOAD_CACHE';

function openDatabase() {
  return new Promise((resolve, reject) => {
    var request = indexedDB.open(INDEX_DB_NAME, 1);
    // Database setup...
  });
}

function cacheRemotePackage(db, packageName, packageData, packageMeta) {
  // Chunk data into 64MB pieces
  // Store with metadata
  // Handle versioning
}
```

### Cache Key Structure
- Package name as primary key
- UUID for version control
- Chunked storage for large files
- Metadata separate from data

## Command-Line Interface

### Required Arguments
- `data_target`: Output data file path

### Key Options
- `--preload <path>`: Directories/files to pack
- `--exclude <pattern>`: Glob patterns to exclude
- `--js-output <file>`: JavaScript loader output
- `--data-target <name>`: Override data file name in JS
- `--lz4`: Enable LZ4 compression
- `--use-preload-cache`: Enable IndexedDB caching
- `--no-node`: Exclude Node.js support
- `--use-preload-plugins`: Enable plugin system

### Environment Variables
- `PHPX_PACKER_CACHE_DIR`: Cache directory location
- `PHPX_PACKER_COMPRESSION_LEVEL`: LZ4 compression level
- `PHPX_PACKER_VERBOSE`: Enable verbose output

## Integration with PHPX Ecosystem

### Build Pipeline Integration
```json
{
  "scripts": {
    "wasm": "php vendor/bin/packer dist.data --preload build/ --js-output=dist.js --lz4",
    "wasm:dev": "php vendor/bin/packer dev.data --preload build/ --js-output=dev.js"
  }
}
```

### WASM Runtime Loading
```javascript
// In PHPX-WasmRuntime
Module.preRun = Module.preRun || [];
Module.preRun.push(function() {
  // Files available in virtual FS
  var phpCode = Module.FS.readFile('/app/index.php', { encoding: 'utf8' });
});
```

### File System Mapping
- Host paths → Virtual paths
- Maintains directory structure
- Handles path separators
- Preserves permissions

## Performance Considerations

### Packing Performance
- **File I/O**: Streaming reads for large files
- **Memory Usage**: Chunked processing
- **Compression**: Parallel processing possible
- **Output**: Buffered writes

### Runtime Performance
- **Initial Load**: Compressed reduces network transfer
- **Cache Hits**: Near-instant subsequent loads
- **Decompression**: ~10-20ms for typical app
- **Memory**: Files loaded on-demand

### Optimization Strategies
1. **Selective Packing**: Only include necessary files
2. **Compression Tuning**: Balance size vs speed
3. **Cache Warming**: Preload critical files
4. **Lazy Loading**: Load files as needed

## Development Guidelines

### Adding New Features
1. Extend FilePacker with event system
2. Add command-line options in packer.php
3. Update JavaScript templates
4. Add tests for new functionality

### Debugging Pack Issues
```bash
# Verbose output
php packer.php output.data --preload files/ -v

# List packed files
php packer.php --list output.data

# Verify pack integrity
php packer.php --verify output.data
```

### Common Issues
- **Path Separators**: Always use forward slashes
- **File Permissions**: Preserve execute bits
- **Large Files**: Consider chunking
- **Binary Files**: Ensure proper handling

## Testing Approach

### Unit Tests
- Metadata generation
- Path normalization
- Exclusion patterns
- Compression/decompression

### Integration Tests
- Full packing pipeline
- JavaScript output validation
- Cache functionality
- Emscripten compatibility

### End-to-End Tests
```bash
# Pack test application
php packer.php test.data --preload tests/fixtures/app/

# Verify with Emscripten
docker run -v $PWD:/src emscripten/emsdk \
  python3 /emsdk/upstream/emscripten/tools/file_packager.py \
  test-emcc.data --preload /src/tests/fixtures/app/

# Compare outputs
diff test.data test-emcc.data
```

## Emscripten Compatibility

### File Format
- Binary concatenation of files
- Metadata in specific JSON structure
- Compatible with FS.createPreloadedFile
- Supports MEMFS and IDBFS

### JavaScript API
```javascript
// Emscripten Module API
Module.locateFile = function(path) {
  return 'assets/' + path;
};

Module.preloadedFiles = {};
Module.preloadedDirectories = {};
```

### Virtual File System
- Maps to Emscripten's FS API
- Supports directories
- Handles special files
- Integrates with PHPX runtime

## Advanced Usage

### Custom Loaders
```php
class CustomPacker extends FilePacker {
    protected function processFile($file) {
        // Custom processing
        $content = parent::processFile($file);
        return $this->transform($content);
    }
}
```

### Plugin System
```javascript
Module.preloadPlugins = Module.preloadPlugins || [];
Module.preloadPlugins.push({
  canHandle: function(name) {
    return name.endsWith('.custom');
  },
  handle: function(byteArray, name, onload, onerror) {
    // Custom handling
  }
});
```

### Streaming Support
```php
// For very large packages
$packer->packStream($inputDir, $outputStream, [
    'chunkSize' => 1024 * 1024, // 1MB chunks
    'onProgress' => function($bytes, $total) {
        echo "Progress: " . ($bytes / $total * 100) . "%\n";
    }
]);
```