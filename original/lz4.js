//


var LZ4JS = (function() { // Wrap in IIFE to avoid polluting global scope

var util = {};

util.readU32 = function (b, n) {
  var x = 0;
  x |= b[n++] << 0;
  x |= b[n++] << 8;
  x |= b[n++] << 16;
  x |= b[n++] << 24;
  return x;
};

util.writeU32 = function (b, n, x) {
  b[n++] = (x >> 0) & 0xff;
  b[n++] = (x >> 8) & 0xff;
  b[n++] = (x >> 16) & 0xff;
  b[n++] = (x >> 24) & 0xff;
};

util.readU64 = function (b, n) {
  var x = 0;
  x |= b[n++] << 0;
  x |= b[n++] << 8;
  x |= b[n++] << 16;
  x |= b[n++] << 24;
  x |= b[n++] << 32; // JS converts to float beyond 32 bits
  x |= b[n++] << 40;
  x |= b[n++] << 48;
  x |= b[n++] << 56;
  console.warn("Reading 64-bit value; precision might be lost for very large numbers.");
  return x;
};


util.hashU32 = function (a) {
  a = a | 0;
  return (((a * 2654435761) | 0) >>> 0) % (1 << 16); // Simplified hash for JS
};


var xxhash = {
    hash: function(seed, buf, start, len) {
        console.warn("XXH32 checksum validation not fully implemented in this JS port.");
        var h32 = seed + len;
        for (var i = start; i < start + len; i++) {
            h32 = ((h32 << 5) - h32 + buf[i]) | 0;
        }
        return h32 >>> 0;
    }
};



var minMatch = 4;
var minLength = 13;
var searchLimit = 5;
var skipTrigger = 6;
var hashSize = 1 << 16;

var mlBits = 4;
var mlMask = (1 << mlBits) - 1;
var runBits = 4;
var runMask = (1 << runBits) - 1;


var magicNum = 0x184D2204;

var fdContentChksum = 0x4;
var fdContentSize = 0x8;
var fdBlockChksum = 0x10;
var fdVersion = 0x40;
var fdVersionMask = 0xC0;

var bsUncompressed = 0x80000000;
var bsDefault = 7;
var bsShift = 4;
var bsMask = 7;
var bsMap = {
4: 0x10000,
5: 0x40000,
6: 0x100000,
7: 0x400000
};


function makeHashTable () {
try {
return new Uint32Array(hashSize);
} catch (error) {
var hashTable = new Array(hashSize);

for (var i = 0; i < hashSize; i++) {
hashTable[i] = 0;
}

return hashTable;
}
}

function clearHashTable (table) {
for (var i = 0; i < hashSize; i++) {
hashTable[i] = 0;
}
}

function makeBuffer (size) {
try {
return new Uint8Array(size);
} catch (error) {
var buf = new Array(size);

for (var i = 0; i < size; i++) {
buf[i] = 0;
}

return buf;
}
}

function sliceArray (array, start, end) {
if (array.slice) {
    return array.slice(start, end);
}

var len = array.length;

start = start | 0;
start = (start < 0) ? Math.max(len + start, 0) : Math.min(start, len);

end = (end === undefined) ? len : end | 0;
end = (end < 0) ? Math.max(len + end, 0) : Math.min(end, len);

var arraySlice;
try {
    arraySlice = new Uint8Array(end - start);
} catch(e) {
    arraySlice = new Array(end - start);
}

for (var i = start, n = 0; i < end;) {
    arraySlice[n++] = array[i++];
}

return arraySlice;

}


var exports = {}; // Use a local exports object

exports.compressBound = function compressBound (n) {
return (n + (n / 255) + 16) | 0;
};

exports.decompressBound = function decompressBound (src) {
var sIndex = 0;

if (util.readU32(src, sIndex) !== magicNum) {
throw new Error('invalid magic number');
}

sIndex += 4;

var descriptor = src[sIndex++];

if ((descriptor & fdVersionMask) !== fdVersion) {
throw new Error('incompatible descriptor version ' + (descriptor & fdVersionMask));
}

var useBlockSum = (descriptor & fdBlockChksum) !== 0;
var useContentSize = (descriptor & fdContentSize) !== 0;

var bsIdx = (src[sIndex++] >> bsShift) & bsMask;

if (bsMap[bsIdx] === undefined) {
throw new Error('invalid block size ' + bsIdx);
}

var maxBlockSize = bsMap[bsIdx];

if (useContentSize) {
return util.readU64(src, sIndex);
}

sIndex++;

var maxSize = 0;
while (true) {
var blockSize = util.readU32(src, sIndex);
sIndex += 4;

if (blockSize === 0) { // End mark
    break;
}

if (useBlockSum) {
    sIndex += 4;
}

if (blockSize & bsUncompressed) {
    blockSize &= ~bsUncompressed; // Remove uncompressed flag
    maxSize += blockSize;
    sIndex += blockSize; // Skip the uncompressed data
} else {
    maxSize += maxBlockSize;
    sIndex += blockSize; // Skip the compressed data
}

}
return maxSize;
};

exports.makeBuffer = makeBuffer;

exports.decompressBlock = function decompressBlock (src, dst, sIndex, sLength, dIndex) {
var mLength, mOffset, sEnd, n, i;
var hasCopyWithin = dst.copyWithin !== undefined && dst.fill !== undefined;

sEnd = sIndex + sLength;

while (sIndex < sEnd) {
var token = src[sIndex++];

var literalCount = (token >> 4);
if (literalCount > 0) {
if (literalCount === 0xf) {
while (true) {
var nextByte = src[sIndex++];
literalCount += nextByte;
if (nextByte !== 0xff) {
break;
}
if (sIndex >= sEnd) throw new Error("Malformed LZ4 stream: literal length");
}
}

if (sIndex + literalCount > sEnd) throw new Error("Malformed LZ4 stream: literal copy");
if (typeof dst.set === 'function' && typeof src.subarray === 'function') {
    dst.set(src.subarray(sIndex, sIndex + literalCount), dIndex);
    dIndex += literalCount;
    sIndex += literalCount;
} else {
    for (n = sIndex + literalCount; sIndex < n;) {
        dst[dIndex++] = src[sIndex++];
    }
}
}

if (sIndex >= sEnd) {
break;
}

mLength = (token & 0xf);

if (sIndex + 1 >= sEnd) throw new Error("Malformed LZ4 stream: offset");
mOffset = src[sIndex++] | (src[sIndex++] << 8);
if (mOffset === 0 || dIndex - mOffset < 0) throw new Error("Malformed LZ4 stream: invalid offset " + mOffset);


if (mLength === 0xf) {
while (true) {
if (sIndex >= sEnd) throw new Error("Malformed LZ4 stream: match length");
var nextByte = src[sIndex++];
mLength += nextByte;
if (nextByte !== 0xff) {
break;
}
}
}

mLength += minMatch;

var matchPos = dIndex - mOffset;
var matchEnd = dIndex + mLength;

if (matchPos < 0 || matchEnd > dst.length) {
    throw new Error("Malformed LZ4 stream: match copy out of bounds");
}

if (hasCopyWithin && mOffset === 1) {
    dst.fill(dst[dIndex - 1] | 0, dIndex, dIndex + mLength);
    dIndex += mLength;
} else if (hasCopyWithin && mOffset > mLength && mLength > 31) {
    dst.copyWithin(dIndex, matchPos, matchPos + mLength);
    dIndex += mLength;
} else {
    for (i = matchPos; dIndex < matchEnd;) {
        dst[dIndex++] = dst[i++] | 0;
    }
}
}

return dIndex;
};


exports.decompressFrame = function decompressFrame (src, dst) {
var useBlockSum, useContentSum, useContentSize, descriptor;
var sIndex = 0;
var dIndex = 0;

if (src.length < 4 || util.readU32(src, sIndex) !== magicNum) {
throw new Error('invalid magic number');
}
sIndex += 4;

if (sIndex >= src.length) throw new Error("Malformed LZ4 stream: missing descriptor");
descriptor = src[sIndex++];

if ((descriptor & fdVersionMask) !== fdVersion) {
throw new Error('incompatible descriptor version');
}

useBlockSum = (descriptor & fdBlockChksum) !== 0;
useContentSum = (descriptor & fdContentChksum) !== 0;
useContentSize = (descriptor & fdContentSize) !== 0;

if (sIndex >= src.length) throw new Error("Malformed LZ4 stream: missing block size");
var bsIdx = (src[sIndex++] >> bsShift) & bsMask;

if (bsMap[bsIdx] === undefined) {
throw new Error('invalid block size');
}

if (useContentSize) {
if (sIndex + 8 > src.length) throw new Error("Malformed LZ4 stream: missing content size");
console.warn("Content size flag present but not used for buffer allocation in this JS port.");
sIndex += 8;
}

if (sIndex >= src.length) throw new Error("Malformed LZ4 stream: missing descriptor checksum");
var descriptorHash = src[sIndex++];
var calculatedHash = (xxhash.hash(0, src, 4, sIndex - 5) >> 8) & 0xFF; // Hash bytes from descriptor flags to blocksize byte
if (descriptorHash !== calculatedHash) {
    console.warn("LZ4 descriptor checksum mismatch. Proceeding anyway.");
}


while (true) {
if (sIndex + 4 > src.length) throw new Error("Malformed LZ4 stream: truncated block size");
var compSize;

compSize = util.readU32(src, sIndex);
sIndex += 4;

if (compSize === 0) { // End marker
    break;
}

if (useBlockSum) {
    if (sIndex + 4 > src.length) throw new Error("Malformed LZ4 stream: truncated block checksum");
    console.warn("Block checksum flag present but checksum not verified in this JS port.");
    sIndex += 4;
}

if ((compSize & bsUncompressed) !== 0) {
compSize &= ~bsUncompressed;

if (sIndex + compSize > src.length) throw new Error("Malformed LZ4 stream: truncated uncompressed block");
if (dIndex + compSize > dst.length) throw new Error("Output buffer too small for uncompressed block");

if (typeof dst.set === 'function' && typeof src.subarray === 'function') {
    dst.set(src.subarray(sIndex, sIndex + compSize), dIndex);
    dIndex += compSize;
    sIndex += compSize;
} else {
    for (var j = 0; j < compSize; j++) {
        dst[dIndex++] = src[sIndex++];
    }
}
} else {
if (sIndex + compSize > src.length) throw new Error("Malformed LZ4 stream: truncated compressed block");
var expectedDIndex = dIndex; // Store start index for this block
try {
    dIndex = exports.decompressBlock(src, dst, sIndex, compSize, dIndex);
} catch (e) {
    throw new Error("Error during block decompression: " + e.message);
}
sIndex += compSize;
}
}

if (useContentSum) {
if (sIndex + 4 > src.length) throw new Error("Malformed LZ4 stream: truncated content checksum");
console.warn("Content checksum flag present but checksum not verified in this JS port.");
sIndex += 4;
}

if (sIndex < src.length && util.readU32(src, sIndex) !== 0) {
    console.warn("LZ4 stream potentially has trailing data after end marker at index " + sIndex);
}


return dIndex; // Return the actual decompressed size
};


exports.decompress = function decompress (src, maxSize) {
var dst, size;

if (!(src instanceof Uint8Array) && !(src instanceof Array) && !(typeof Buffer !== 'undefined' && src instanceof Buffer)) {
    throw new Error("Input data must be Uint8Array, Array, or Buffer");
}
if (!(src instanceof Uint8Array)) {
    try {
        src = new Uint8Array(src);
    } catch (e) {
        throw new Error("Failed to convert input data to Uint8Array: " + e.message);
    }
}


if (maxSize === undefined) {
try {
    maxSize = exports.decompressBound(src);
} catch (e) {
    throw new Error("Failed to determine decompress bound: " + e.message);
}
}
if (maxSize < 0 || typeof maxSize !== 'number') {
    throw new Error("Invalid maxSize provided for decompression: " + maxSize);
}

try {
    dst = exports.makeBuffer(maxSize);
} catch (e) {
    throw new Error("Failed to allocate output buffer of size " + maxSize + ": " + e.message);
}

try {
    size = exports.decompressFrame(src, dst);
} catch (e) {
    throw new Error("Error during frame decompression: " + e.message);
}


if (size !== maxSize) {
try {
    dst = sliceArray(dst, 0, size);
} catch (e) {
    throw new Error("Failed to slice final buffer: " + e.message);
}
}

return dst;
};


return exports; // Return the exports object

})(); // End IIFE
