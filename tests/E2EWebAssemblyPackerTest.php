<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class E2EWebAssemblyPackerTest extends TestCase
{
    public function testWasmPackEmscripten()
    {
        $process = new Process([
            'docker', 'run',
            '-v', __DIR__ . '/fixtures/test_dir:/test_dir',
            '-v', __DIR__ . '/fixtures/build:/dist/build',
            '-w', '/dist',
            'emscripten/emsdk:4.0.6',
            'python3',
            '/emsdk/upstream/emscripten/tools/file_packager.py',
            'build/php-web.data',
            '--use-preload-cache',
            '--preload', '/test_dir',
            '--js-output=build/php-web.data.js',
            '--no-node',
            '--exclude',
            '*/.hidden',
            '*/*.tmp',
            '--export-name=createPhpModule',
        ]);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'Failed to pack with emscripten');
        $this->assertFileExists(__DIR__ . '/fixtures/build/php-web.data');
        $this->assertFileExists(__DIR__ . '/fixtures/build/php-web.data.js');
    }

    public function testWasmPackPurePhp()
    {
        $process = new Process([
            'php', '../../filepackager.php',
            'build-php/php-web.data',
            '--preload', 'test_dir',
            '--js-output=build-php/php-web.data.js',
            '--use-preload-cache',
            '--exclude',
            '*/.hidden',
            '*/*.tmp',
            '--no-node',
            '--debug',
            '--export-name=createPhpModule',
        ]);
        $process->setWorkingDirectory(__DIR__ . '/fixtures/');
        
        // Print the command that will be executed
        //echo "Executing command: " . $process->getCommandLine() . "\n";
        
        $process->run();
        
        // Print the output and any errors
        //echo "Command output:\n" . $process->getOutput() . "\n";
        if ($process->getErrorOutput()) {
            //echo "Command errors:\n" . $process->getErrorOutput() . "\n";
        }
        
        $this->assertTrue($process->isSuccessful(), 'Failed to pack with pure PHP');
        $this->assertFileExists(__DIR__ . '/fixtures/build-php/php-web.data');
        $this->assertFileExists(__DIR__ . '/fixtures/build-php/php-web.data.js');
    }

    public function testCompareFilesXX()
    {
        // First run both packers
        $this->testWasmPackPurePhp();
        $this->testWasmPackEmscripten();

        // Format the JS files
        $process = new Process(['prettier', '--write', __DIR__ . '/fixtures/build-php/php-web.data.js']);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'Failed to format JS file');

        // Compare data files
        $process = new Process(['cmp', '-s', __DIR__ . '/fixtures/build-php/php-web.data', __DIR__ . '/fixtures/testing/no-compression.data']);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'Data files are not equal');

        // Compare JS files
        $process = new Process(['cmp', '-s', __DIR__ . '/fixtures/build-php/php-web.data.js', __DIR__ . '/fixtures/testing/php-web.data.js']);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'JS files are not equal');
    }

    public function testWasmPackEmscriptenLz4()
    {
        $process = new Process([
            'docker', 'run',
            '-v', __DIR__ . '/fixtures/test_dir:/test_dir',
            '-v', __DIR__ . '/fixtures/build:/dist/build',
            '-w', '/dist',
            'emscripten/emsdk:4.0.6',
            'python3',
            '/emsdk/upstream/emscripten/tools/file_packager.py',
            'build/php-web.data',
            '--use-preload-cache',
            '--lz4',
            '--preload', '/test_dir',
            '--js-output=build/php-web.data.js',
            '--no-node',
            '--exclude',
            '*/.hidden',
            '*/*.tmp',
            '--export-name=createPhpModule',
        ]);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'Failed to pack with emscripten LZ4');
        $this->assertFileExists(__DIR__ . '/fixtures/build/php-web.data');
        $this->assertFileExists(__DIR__ . '/fixtures/build/php-web.data.js');
    }

    public function testWasmPackPurePhpLz4()
    {
        $process = new Process([
            'php', '../../filepackager.php',
            'build-php/php-web.data',
            '--preload', 'test_dir',
            '--js-output=build-php/php-web.data.js',
            '--lz4',
            '--use-preload-cache',
            '--exclude',
            '*/.hidden',
            '*/*.tmp',
            '--no-node',
            '--export-name=createPhpModule',
        ]);
        $process->setWorkingDirectory(__DIR__ . '/fixtures/');

        $process->run();
        $this->assertTrue($process->isSuccessful(), 'Failed to pack with pure PHP LZ4');
        $this->assertFileExists(__DIR__ . '/fixtures/build-php/php-web.data');
        $this->assertFileExists(__DIR__ . '/fixtures/build-php/php-web.data.js');
    }

    public function testCompareFilesLz4()
    {
        // First run both packers
        $this->testWasmPackPurePhpLz4();
        $this->testWasmPackEmscriptenLz4();

        // Format the JS files
        $process = new Process(['prettier', '--write', __DIR__ . '/fixtures/build-php/php-web.data.js']);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'Failed to format JS file');

        // Compare data files
        $process = new Process(['cmp', '-s', __DIR__ . '/fixtures/build-php/php-web.data', __DIR__ . '/fixtures/testing/compression.data']);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'Data files are not equal');

        // Compare JS files
        $process = new Process(['cmp', '-s', __DIR__ . '/fixtures/build-php/php-web.data.js', __DIR__ . '/fixtures/testing/compression-web.data.js']);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'JS files are not equal');

        // php unit compare two binary files (with binary diff in pure php)
        $this->assertEquals(file_get_contents(__DIR__ . '/fixtures/build-php/php-web.data'), file_get_contents(__DIR__ . '/fixtures/testing/compression.data'));
        $this->assertEquals(file_get_contents(__DIR__ . '/fixtures/build-php/php-web.data.js'), file_get_contents(__DIR__ . '/fixtures/testing/compression-web.data.js'));
    }
}
