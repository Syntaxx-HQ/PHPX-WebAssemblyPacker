import * as fs from 'fs';
import * as path from 'path';
import { fileURLToPath } from 'url';

// Define a basic assert function needed by mini-lz4.js
function assert(condition, message) {
    if (!condition) {
        throw new Error(message || "Assertion failed");
    }
}
globalThis.assert = assert; // Make it globally available if needed by the module


// Resolve the path to mini-lz4.js relative to this script
// Assuming mini-lz4.js is in the emscripten checkout relative to the project structure
// Adjust the relative path if necessary. Let's assume it's accessible via a known path.
// We previously used '../emscripten/third_party/mini-lz4.js' relative to lz4-compress.mjs
// Let's try finding it relative to this script's location.
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
// Go up one level from file_packager_test_case, then into emscripten/third_party
const miniLz4Path = path.resolve(__dirname, '../emscripten/third_party/mini-lz4.js');

// Dynamically import mini-lz4.js
let MiniLZ4;
try {
    MiniLZ4 = (await import(miniLz4Path)).default;
     if (!MiniLZ4 || typeof MiniLZ4.compress !== 'function') {
        // Sometimes dynamic import wraps default export, try accessing directly
        const module = await import(miniLz4Path);
        MiniLZ4 = module.MiniLZ4 || module.default; // Adjust based on actual export
        if (!MiniLZ4 || typeof MiniLZ4.compress !== 'function') {
             throw new Error('Could not find MiniLZ4.compress function in the imported module.');
        }
     }
} catch (err) {
    console.error(`Error importing mini-lz4.js from ${miniLz4Path}: ${err.message}`);
    process.exit(1);
}


// Read input from stdin
const inputBuffer = fs.readFileSync(0); // Read stdin as buffer

// Prepare output buffer
const inputData = new Uint8Array(inputBuffer);
const outputBound = MiniLZ4.compressBound(inputData.length);
if (outputBound === 0 && inputData.length > 0) {
    console.error("Input too large for JS LZ4 compression or compressBound failed.");
    process.exit(1);
}

// Handle empty input explicitly, mirroring PHP behavior
if (inputData.length === 0) {
    process.stdout.write(''); // Write empty string to stdout
    process.exit(0);
}

const outputBuffer = new Uint8Array(outputBound);

// Compress
try {
    const compressedSize = MiniLZ4.compress(inputData, outputBuffer);

    if (compressedSize === 0 && inputData.length > 0) {
        // JS compress returns 0 if it cannot compress. Output the original input buffer.
        process.stdout.write(inputBuffer);
    } else if (compressedSize > 0) {
        // Write compressed data to stdout
        process.stdout.write(outputBuffer.subarray(0, compressedSize));
    }
    // If compressedSize is 0 and inputData.length is 0, it was handled earlier.
    // No need for an else block here.
} catch (error) {
    console.error(`JS LZ4 Compression Error: ${error.message}`);
    process.exit(1);
}
