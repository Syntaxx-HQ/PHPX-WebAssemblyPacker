<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/lz4_php.php';

echo "Starting LZ4 PHP Implementation Tests...\n\n";

$testCases = [
    "Empty String" => "",
    "Short String" => "abc",
    "Simple Repeat" => str_repeat("a", 100),
    "Longer Repeat" => str_repeat("abcdef", 200),
    "No Repeat" => implode('', range('a', 'z')) . implode('', range('A', 'Z')) . implode('', range(0, 9)),
    "Binary Data" => implode('', array_map('chr', range(0, 255))),
    "Long Random" => '', // Will generate below
    "Very Long Repeat" => str_repeat("ababcdcd", 10000), // Test > 64k offset possibility (though current simple implementation might not hit it optimally)
];

for ($i = 0; $i < 5000; $i++) {
    $testCases["Long Random"] .= chr(rand(0, 255));
}


$passed = 0;
$failed = 0;

foreach ($testCases as $name => $input) {
    echo "--- Test Case: {$name} ---\n";
    echo "Input Length: " . strlen($input) . "\n";

    try {
        $startTimeCompress = microtime(true);
        $compressed = LZ4_PHP::compress($input);
        $endTimeCompress = microtime(true);
        $compressTime = round(($endTimeCompress - $startTimeCompress) * 1000);
        echo "Compressed Length: " . strlen($compressed) . " (took {$compressTime} ms)\n";

        $jsCompressed = null;
        $jsCompressError = null;
        $nodeScriptPath = __DIR__ . '/lz4-js-compress-cli.mjs';
        $descriptorspec = [
           0 => ["pipe", "r"],  // stdin is a pipe that the child will read from
           1 => ["pipe", "w"],  // stdout is a pipe that the child will write to
           2 => ["pipe", "w"]   // stderr is a pipe that the child will write to
        ];
        $pipes = [];
        $process = proc_open("node {$nodeScriptPath}", $descriptorspec, $pipes);

        if (is_resource($process)) {
            fwrite($pipes[0], $input);
            fclose($pipes[0]);

            $jsCompressed = stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            $jsCompressError = stream_get_contents($pipes[2]);
            fclose($pipes[2]);

            $return_value = proc_close($process);

            if ($return_value !== 0) {
                $jsCompressError = "Node script exited with code {$return_value}. Error: " . ($jsCompressError ?: 'Unknown error');
                $jsCompressed = null; // Indicate failure
            } elseif ($jsCompressError) {
                 echo "JS Compression STDERR (non-fatal?): {$jsCompressError}\n";
            }
        } else {
            $jsCompressError = "Failed to execute Node.js script.";
        }

        if ($jsCompressed === null) {
            echo "JS Compression Error: {$jsCompressError}\n";
        } else {
             echo "JS Compressed Length: " . strlen($jsCompressed) . "\n";
             $jsFailedToCompress = ($jsCompressed === $input);
             if ($jsFailedToCompress && strlen($input) > 0) { // Avoid false positive for empty string
                 echo "JS reported failure to compress (returned original data).\n";
             }

             $phpFailedToCompress = ($compressed === $input);
              if ($phpFailedToCompress && strlen($input) > 0) { // Avoid false positive for empty string
                 echo "PHP reported failure to compress (returned original data).\n";
             }

             if ($phpFailedToCompress && $jsFailedToCompress) {
                 echo "PHP vs JS Compression: OK (Both failed to compress)\n";
             } elseif ($phpFailedToCompress && !$jsFailedToCompress) {
                 $failed++;
                 echo "PHP vs JS Compression: FAILED! PHP failed/returned original, JS succeeded.\n";
             } elseif (!$phpFailedToCompress && $jsFailedToCompress) {
                  $failed++;
                  echo "PHP vs JS Compression: FAILED! PHP succeeded, JS failed/returned original.\n";
             } elseif ($compressed === $jsCompressed) {
                 echo "PHP vs JS Compression: OK\n";
             } else {
                 $failed++;
                 echo "PHP vs JS Compression: FAILED! Outputs differ.\n";
             }
        }


        if ($name === "Empty String") {
            $phpOk = ($compressed === "");
            $jsOk = ($jsCompressed === "");
            $jsSkipped = ($jsCompressed === null);

            if ($phpOk && $jsOk) {
                 echo "PHP & JS Compression returned empty string for empty input as expected.\n";
                 $passed++;
                 echo "Result: PASSED (Empty Input)\n\n";
            } elseif ($phpOk && $jsSkipped) {
                 echo "PHP OK (empty), JS Failed/Skipped.\n";
                 $passed++;
                 echo "Result: PASSED (Empty Input - JS Skipped)\n\n";
            } else {
                 $failed++;
                 echo "Verification: FAILED! Empty string handling mismatch.\n";
                 echo "PHP Output: " . ($phpOk ? "OK (empty)" : "FAIL ('" . bin2hex($compressed) . "')") . "\n";
                 echo "JS Output: " . ($jsOk ? "OK (empty)" : ($jsSkipped ? "SKIPPED" : "FAIL ('" . bin2hex($jsCompressed) . "')")) . "\n";
                 echo "Result: FAILED (Empty Input Mismatch)\n\n";
            }
            continue; // Move to the next test case
        }


        $roundTripOk = true; // Assume OK if not attempted
        if ($compressed !== $input || strlen($input) === 0) { // Decompress if compressed OR if input was empty (compressed is also empty)
             $startTimeDecompress = microtime(true);
             $decompressed = LZ4_PHP::decompress($compressed, strlen($input));
             $endTimeDecompress = microtime(true);
             $decompressTime = round(($endTimeDecompress - $startTimeDecompress) * 1000);
             echo "Decompressed Length: " . strlen($decompressed) . " (took {$decompressTime} ms)\n";

             if ($decompressed === $input) {
                 echo "Round-Trip Verification: OK\n";
                 $roundTripOk = true;
             } else {
                 $failed++; // Increment failure for round-trip mismatch
                 echo "Round-Trip Verification: FAILED! Decompressed data does not match original.\n";
                 $roundTripOk = false;
             }
        } else {
             echo "Round-Trip Verification: SKIPPED (PHP returned original data)\n";
             $roundTripOk = true; // Treat as OK since compression didn't happen
        }

        $currentFailures = $failed; // Store failure count before this test
        $jsComparisonOk = ($jsCompressed !== null && $compressed === $jsCompressed);

        if ($roundTripOk && ($jsCompressed === null || $jsComparisonOk)) {
             $passed++;
             echo "Result: PASSED\n";
        } else {
             echo "Result: FAILED\n";
        }

    } catch (\Exception $e) {
        $failed++;
        echo "Error: " . $e->getMessage() . "\n";
        echo "Result: FAILED (Exception)\n";
    }
    echo "\n";
}

echo "--- Test Summary ---\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
echo "Total: " . ($passed + $failed) . "\n";

exit($failed > 0 ? 1 : 0); // Exit with non-zero code if any tests failed

?>
