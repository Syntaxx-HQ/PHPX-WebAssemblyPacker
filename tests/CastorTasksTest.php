<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use function Castor\run;

class CastorTasksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Ensure test directories exist
        if (!file_exists(__DIR__ . '/../build')) {
            mkdir(__DIR__ . '/../build', 0777, true);
        }
        if (!file_exists(__DIR__ . '/../build-php')) {
            mkdir(__DIR__ . '/../build-php', 0777, true);
        }
    }

    public function testWasmPackEmscripten()
    {
        $process = new Process([
            'docker', 'run',
            '-v', __DIR__ . '/../test_dir:/test_dir',
            '-v', __DIR__ . '/../build:/dist/build',
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
        $this->assertFileExists(__DIR__ . '/../build/php-web.data');
        $this->assertFileExists(__DIR__ . '/../build/php-web.data.js');
    }

    public function testWasmPackPurePhp()
    {
        $process = new Process([
            'php', 'create_data.php',
            'build-php/php-web.data',
            '--preload', __DIR__ . '/../test_dir',
            '--js-output=build-php/php-web.data.js',
            '--use-preload-cache',
            '--exclude',
            '*/.hidden',
            '*/*.tmp',
            '--no-node',
            '--export-name=createPhpModule',
        ]);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'Failed to pack with pure PHP');
        $this->assertFileExists(__DIR__ . '/../build-php/php-web.data');
        $this->assertFileExists(__DIR__ . '/../build-php/php-web.data.js');
    }

    public function testCompareFiles()
    {
        // First run both packers
        $this->testWasmPackPurePhp();
        $this->testWasmPackEmscripten();

        // Format the JS files
        $process = new Process(['prettier', '--write', __DIR__ . '/../build-php/php-web.data.js']);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'Failed to format JS file');

        // Compare data files
        $process = new Process(['cmp', '-s', __DIR__ . '/../build-php/php-web.data', __DIR__ . '/../testing/no-compression.data']);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'Data files are not equal');

        // Compare JS files
        $process = new Process(['cmp', '-s', __DIR__ . '/../build-php/php-web.data.js', __DIR__ . '/../testing/php-web.data.js']);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'JS files are not equal');
    }

    public function testWasmPackEmscriptenLz4()
    {
        $process = new Process([
            'docker', 'run',
            '-v', __DIR__ . '/../test_dir:/test_dir',
            '-v', __DIR__ . '/../build:/dist/build',
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
        $this->assertFileExists(__DIR__ . '/../build/php-web.data');
        $this->assertFileExists(__DIR__ . '/../build/php-web.data.js');
    }

    public function testWasmPackPurePhpLz4()
    {
        $process = new Process([
            'php', 'create_data.php',
            'build-php/php-web.data',
            '--preload', __DIR__ . '/../test_dir',
            '--js-output=build-php/php-web.data.js',
            '--lz4',
            '--use-preload-cache',
            '--exclude',
            '*/.hidden',
            '*/*.tmp',
            '--no-node',
            '--export-name=createPhpModule',
        ]);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'Failed to pack with pure PHP LZ4');
        $this->assertFileExists(__DIR__ . '/../build-php/php-web.data');
        $this->assertFileExists(__DIR__ . '/../build-php/php-web.data.js');
    }

    public function testCompareFilesLz4()
    {
        // First run both packers
        $this->testWasmPackPurePhpLz4();
        $this->testWasmPackEmscriptenLz4();

        // Format the JS files
        $process = new Process(['prettier', '--write', __DIR__ . '/../build-php/php-web.data.js']);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'Failed to format JS file');

        // Compare data files
        $process = new Process(['cmp', '-s', __DIR__ . '/../build-php/php-web.data', __DIR__ . '/../testing/compression.data']);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'Data files are not equal');

        // Compare JS files
        $process = new Process(['cmp', '-s', __DIR__ . '/../build-php/php-web.data.js', __DIR__ . '/../testing/compression-web.data.js']);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'JS files are not equal');
    }
} 