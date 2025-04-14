<?php

declare(strict_types=1);


class LZ4_PHP {
    private const MAX_INPUT_SIZE = 0x7E000000;
    private const MIN_MATCH = 4;
    private const HASH_LOG = 16;
    private const HASH_SHIFT = (self::MIN_MATCH * 8) - self::HASH_LOG; // Should be 16
    private const HASH_SIZE = 1 << self::HASH_LOG; // 65536

    private const COPY_LENGTH = 8;
    private const LAST_LITERALS = 5;
    private const MF_LIMIT = self::COPY_LENGTH + self::MIN_MATCH; // 12
    private const SKIP_STRENGTH = 6;

    private const ML_BITS = 4;
    private const ML_MASK = (1 << self::ML_BITS) - 1; // 15
    private const RUN_BITS = 8 - self::ML_BITS; // 4
    private const RUN_MASK = (1 << self::RUN_BITS) - 1; // 15

    private const HASHER = 0x9E3779B1;

    private array $hashTable;

    public function __construct() {
        $this->hashTable = array_fill(0, self::HASH_SIZE, 0);
    }

    /**
     * Calculate 32-bit hash using JavaScript's Math.imul logic.
     * PHP needs careful handling for 32-bit unsigned multiplication.
     */
    private static function calculateHash(int $sequence): int {
        $product = self::imul($sequence, self::HASHER);
        $hash = self::unsignedRightShift($product, self::HASH_SHIFT);
        return $hash; // Direct use approach (matching JS more closely)
    }

    /**
     * Emulates JavaScript's Math.imul for 32-bit signed integer multiplication.
     */
    private static function imul(int $a, int $b): int {
        $a &= 0xFFFFFFFF;
        $b &= 0xFFFFFFFF;

        $ah = ($a >> 16) & 0xFFFF;
        $al = $a & 0xFFFF;
        $bh = ($b >> 16) & 0xFFFF;
        $bl = $b & 0xFFFF;

        $p00 = $al * $bl;
        $p10 = $ah * $bl;
        $p01 = $al * $bh;

        $low32 = $p00 & 0xFFFFFFFF;
        $mid16_shifted = ($p10 << 16) & 0xFFFFFFFF;
        $mid01_shifted = ($p01 << 16) & 0xFFFFFFFF;

        $result = ($low32 + $mid16_shifted + $mid01_shifted) & 0xFFFFFFFF;

        if ($result >= 0x80000000) {
            return $result - 0x100000000;
        } else {
            return $result;
        }
    }

     /**
      * Emulates JavaScript's unsigned right shift (>>>).
      */
     private static function unsignedRightShift(int $a, int $n): int {
         if ($n === 0) return $a & 0xFFFFFFFF; // Return as unsigned 32-bit
         $a &= 0xFFFFFFFF;
         return ($a >> $n); // PHP's right shift on positive numbers behaves like unsigned shift
     }


    /**
     * Main public compression method.
     */
    public static function compress(string $input): string {
        $instance = new self(); // Create instance to use non-static hashTable
        $inputBytes = array_values(unpack('C*', $input)); // Convert string to byte array
        $inputLength = count($inputBytes);
        if ($inputLength === 0) {
            return ''; // Handle empty input explicitly
        }
        $outputBound = self::compressBound($inputLength);
        if ($outputBound === 0) {
             throw new \RuntimeException("Input too large for LZ4 compression.");
        }
        $outputString = ''; // Passed by reference to compressBlock

        $compressedSize = $instance->compressBlock($inputBytes, $outputString, 0, 0, $inputLength);

        if ($compressedSize === 0) {
            return $input; // Return original input string
        }

        return $outputString;
    }

     /**
      * Compresses a block of data.
      * Modifies $dst string directly.
      *
      * @param int[] $src Source byte array.
      * @param string &$dst Destination string (passed by reference).
      * @param int $pos Current position in source.
      * @param int $dpos Current position (byte count) in destination string.
      * @param int $srcLength Length of the source data to process.
      * @return int The final size (byte count) of the compressed data in the destination string.
      */
     private function compressBlock(array $src, string &$dst, int $pos, int $dpos, int $srcLength): int {
         $anchor = 0;

         if ($srcLength >= self::MAX_INPUT_SIZE) {
             throw new \RangeException("Input too large");
         }

         if ($srcLength > self::MF_LIMIT) {
             $step = 1;
             $findMatchAttempts = (1 << self::SKIP_STRENGTH) + 3;
             $srcLengthLimit = $srcLength - self::MF_LIMIT;

             while ($pos < $srcLengthLimit) { // Match JS loop condition
               if ($pos + 3 >= $srcLengthLimit) break; // Need 4 bytes for JS hash sequence, check against limit
               $sequenceLowBits = ($src[$pos+1] << 8) | $src[$pos];
               $sequenceHighBits = ($src[$pos+3] << 8) | $src[$pos+2];
               $sequence = (($sequenceHighBits << 16) | $sequenceLowBits) & 0xFFFFFFFF;
               $hashIndex = self::calculateHash($sequence); // Use the helper function


               $ref = isset($this->hashTable[$hashIndex]) ? ($this->hashTable[$hashIndex] - 1) : -1; // Subtract 1 on lookup, default -1 if not set
               $this->hashTable[$hashIndex] = $pos + 1; // Store pos + 1

               $matchFound = false;
               if ($ref !== -1 && ($pos - $ref) < 0xffff) {
                   if ($ref + 3 < $srcLength) { // Ensure ref indices are valid
                       $refSequenceLowBits = ($src[$ref+1] << 8) | $src[$ref];
                       $refSequenceHighBits = ($src[$ref+3] << 8) | $src[$ref+2];
                       if ($sequenceLowBits === $refSequenceLowBits && $sequenceHighBits === $refSequenceHighBits) {
                           $matchFound = true;
                       }
                   }
               }


                 if (!$matchFound) {
                     $step = $findMatchAttempts++ >> self::SKIP_STRENGTH;
                     $pos += $step;
                     continue;
                 }

                 $findMatchAttempts = (1 << self::SKIP_STRENGTH) + 3;

                 $literals_length = $pos - $anchor;
                 $offset = $pos - $ref;

                $match_start = $pos; // Store start of actual match
                $pos += self::MIN_MATCH;
                $ref += self::MIN_MATCH;

               $match_len_start = $pos; // Start counting length after MIN_MATCH
              while ($pos < $srcLengthLimit && $src[$pos] === $src[$ref]) {
                    $pos++;
                    $ref++;
                }


                 $match_length_extra = $pos - $match_len_start;
                 $total_match_length = $match_length_extra + self::MIN_MATCH; // Keep total for offset calculation later if needed, but use extra for encoding

                 $token = ($match_length_extra < self::ML_MASK) ? $match_length_extra : self::ML_MASK;

                 $litTokenPart = ($literals_length < self::RUN_MASK) ? $literals_length : self::RUN_MASK;
                 $dst .= chr(($litTokenPart << self::ML_BITS) | $token);
                 $dpos++;

                 if ($literals_length >= self::RUN_MASK) {
                     $len = $literals_length - self::RUN_MASK;
                     while ($len >= 255) {
                         $dst .= chr(255);
                         $dpos++;
                         $len -= 255;
                     }
                     $dst .= chr($len);
                     $dpos++;
                 }

                 for ($i = 0; $i < $literals_length; $i++) {
                     $dst .= chr($src[$anchor + $i]);
                     $dpos++;
                 }

                 $dst .= chr($offset & 0xFF);
                 $dst .= chr(($offset >> 8) & 0xFF);
                 $dpos += 2;

                if ($match_length_extra >= self::ML_MASK) {
                    $len = $match_length_extra - self::ML_MASK;
                    while ($len >= 255) {
                         $dst .= chr(255);
                         $dpos++;
                         $len -= 255;
                     }
                     $dst .= chr($len);
                     $dpos++;
                 }

                 $anchor = $pos;
             }
         }


         $literals_length = $srcLength - $anchor;
         if ($literals_length > 0) {
             $litTokenPart = ($literals_length < self::RUN_MASK) ? $literals_length : self::RUN_MASK;
             $dst .= chr($litTokenPart << self::ML_BITS); // Token has 0 match length part
             $dpos++;

             if ($literals_length >= self::RUN_MASK) {
                 $len = $literals_length - self::RUN_MASK;
                 while ($len >= 255) {
                     $dst .= chr(255);
                     $dpos++;
                     $len -= 255;
                 }
                 $dst .= chr($len);
                 $dpos++;
             }

             for ($i = 0; $i < $literals_length; $i++) {
                 $dst .= chr($src[$anchor + $i]);
                 $dpos++;
             }
         }


        if ($anchor === 0 && $srcLength > 0) {
             return 0;
        }

        return $dpos; // Return the number of bytes written to dst string
    }


    /**
     * Calculate the maximum compressed size for a given input size.
     */
    public static function compressBound(int $size): int {
         if ($size < 0 || $size > self::MAX_INPUT_SIZE) {
             return 0; // Indicate error for invalid size
         }
         return (int)floor($size + ($size / 255) + 16);
    }

    private function compareBytes(array $src, int $ref, int $pos, int $srcLengthLimit): bool {
         if ($pos + self::MIN_MATCH > $srcLengthLimit) return false; // Not enough bytes left

         return $src[$ref] === $src[$pos] &&
                $src[$ref + 1] === $src[$pos + 1] &&
                $src[$ref + 2] === $src[$pos + 2] &&
                $src[$ref + 3] === $src[$pos + 3];
    }



    /**
     * Decompress an LZ4 block. Ported from mini-lz4.js uncompressBlock.
     *
     * @param string $input Compressed data string.
     * @param int $outputSize Expected size of the decompressed data.
     * @return string Decompressed data string.
     * @throws \RuntimeException If decompression fails or output size mismatch.
     */
    public static function decompress(string $input, int $outputSize): string {
        $inputBytes = array_values(unpack('C*', $input));
        $inputLength = count($inputBytes);
        $output = array_fill(0, $outputSize, 0);
        $outputPos = 0; // Current position in output array
        $inputPos = 0; // Current position in input byte array

        while ($inputPos < $inputLength) {
            if ($outputPos >= $outputSize) {
                 if ($inputPos === $inputLength) break;
                 throw new \RuntimeException("Malformed LZ4 stream: Output buffer overflow before input exhausted.");
            }

            $token = $inputBytes[$inputPos++];

            $literals_length = ($token >> self::ML_BITS); // High 4 bits
            if ($literals_length === self::RUN_MASK) { // 15 requires reading more bytes
                $len = 255;
                while ($len === 255) {
                    if ($inputPos >= $inputLength) throw new \RuntimeException("Malformed LZ4 stream: Unexpected end while reading literal length.");
                    $len = $inputBytes[$inputPos++];
                    $literals_length += $len;
                }
            }

            if ($literals_length > 0) {
                $literalEndPos = $outputPos + $literals_length;
                if ($literalEndPos > $outputSize) throw new \RuntimeException("Malformed LZ4 stream: Literal copy exceeds output buffer size.");
                $literalInputEndPos = $inputPos + $literals_length;
                 if ($literalInputEndPos > $inputLength) throw new \RuntimeException("Malformed LZ4 stream: Literal copy exceeds input buffer.");

                for ($i = 0; $i < $literals_length; $i++) {
                    $output[$outputPos++] = $inputBytes[$inputPos++];
                }
            }

            if ($inputPos >= $inputLength) {
                break;
            }

            if ($inputPos + 1 >= $inputLength) throw new \RuntimeException("Malformed LZ4 stream: Unexpected end while reading offset.");
            $offset = $inputBytes[$inputPos++] | ($inputBytes[$inputPos++] << 8);

            if ($offset === 0 || $offset > $outputPos) {
                 throw new \RuntimeException("Malformed LZ4 stream: Invalid offset " . $offset . " at output position " . $outputPos);
            }

            $match_length = ($token & self::ML_MASK); // Low 4 bits
            if ($match_length === self::ML_MASK) { // 15 requires reading more bytes
                $len = 255;
                while ($len === 255) {
                     if ($inputPos >= $inputLength) throw new \RuntimeException("Malformed LZ4 stream: Unexpected end while reading match length.");
                     $len = $inputBytes[$inputPos++];
                     $match_length += $len;
                }
            }
            $match_length += self::MIN_MATCH; // Add minimum match length (4)

            $matchPos = $outputPos - $offset; // Position in output to copy from
            $matchEnd = $outputPos + $match_length;

            if ($matchEnd > $outputSize) throw new \RuntimeException("Malformed LZ4 stream: Match copy exceeds output buffer size.");

            for ($i = 0; $i < $match_length; $i++) {
                $output[$outputPos++] = $output[$matchPos++];
            }
        }

        if ($outputPos !== $outputSize) {
            throw new \RuntimeException("Decompression failed: Expected output size {$outputSize}, but got {$outputPos}. Input exhausted: " . ($inputPos >= $inputLength ? 'yes' : 'no'));
        }

        return implode('', array_map('chr', $output));
    }


}
