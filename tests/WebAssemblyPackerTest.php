<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Syntaxx\WebAssemblyPacker\Options;
use Syntaxx\WebAssemblyPacker\Infra\EventManager;
use Syntaxx\WebAssemblyPacker\WebAssemblyPacker;
use Syntaxx\WebAssemblyPacker\DataFile;
use Symfony\Component\Process\Process;

class WebAssemblyPackerTest extends TestCase
{
    private string $fixturesDir;
    private string $buildDir;
    private EventManager $eventManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturesDir = __DIR__ . '/fixtures';
        $this->buildDir = $this->fixturesDir . '/build-php';
        $this->eventManager = new EventManager();

        // Add debug output handler
        /*$this->eventManager->addListener(\Syntaxx\WebAssemblyPacker\Infra\Events\LogEvent::class, function($event) {
            echo $event . PHP_EOL;
        });*/
    }

    public function testPackWithoutLz4()
    {
        // Create build directory if it doesn't exist
        if (!is_dir($this->buildDir)) {
            mkdir($this->buildDir, 0777, true);
        }

        // Create options similar to testWasmPackPurePhp
        $options = new Options($this->fixturesDir);
        $options->jsOutput = $this->buildDir . '/php-web.data.js';
        $options->usePreloadCache = true;
        $options->excludePatterns = ['*/.hidden', '*/*.tmp'];
        $options->supportNode = false;
        $options->exportName = 'createPhpModule';

        // Add test directory to initialDataFiles
        $testDir = $this->fixturesDir . '/test_dir';
        $options->initialDataFiles[] = new DataFile($testDir, $testDir, 'preload', false);

        // Create argv array similar to command line arguments
        $argv = [
            'filepackager.php',
            //$this->buildDir . '/php-web.data',
            'tests/fixtures/build-php/php-web.data',
            '--preload', $this->fixturesDir . '/test_dir',
            '--js-output=' . $this->buildDir . '/php-web.data.js',
            '--use-preload-cache',
            '--exclude', '*/.hidden', '*/*.tmp',
            '--no-node',
            '--export-name=createPhpModule'
        ];

        // Create and run packer
        $webAssemblyPacker = new WebAssemblyPacker($this->eventManager);
        $webAssemblyPacker->pack($options, $argv);

        // Verify files were created
        $this->assertFileExists($this->buildDir . '/php-web.data', 'Data file was not created');
        $this->assertFileExists($this->buildDir . '/php-web.data.js', 'JS file was not created');
    }

    public function testPackWithLz4()
    {
        // Create build directory if it doesn't exist
        if (!is_dir($this->buildDir)) {
            mkdir($this->buildDir, 0777, true);
        }

        // Create options similar to testWasmPackPurePhpLz4
        $options = new Options($this->fixturesDir);
        $options->jsOutput = $this->buildDir . '/php-web.data.js';
        $options->usePreloadCache = true;
        $options->lz4 = true;
        $options->excludePatterns = ['*/.hidden', '*/*.tmp'];
        $options->supportNode = false;
        $options->exportName = 'createPhpModule';
        
        // Add test directory to initialDataFiles
        $testDir = $this->fixturesDir . '/test_dir';
        $options->initialDataFiles[] = new DataFile($testDir, $testDir, 'preload', false);
//'build-php/php-web.data',
        // Create argv array similar to command line arguments
        $argv = [
            'filepackager.php',
            //$this->buildDir . '/php-web.data',
            'tests/fixtures/build-php/php-web.data',
            '--preload', $this->fixturesDir . '/test_dir',
            '--js-output=' . $this->buildDir . '/php-web.data.js',
            '--lz4',
            '--use-preload-cache',
            '--exclude', '*/.hidden', '*/*.tmp',
            '--no-node',
            '--export-name=createPhpModule'
        ];

        // Create and run packer
        $webAssemblyPacker = new WebAssemblyPacker($this->eventManager);
        $webAssemblyPacker->pack($options, $argv);

        // Verify files were created
        $this->assertFileExists($this->buildDir . '/php-web.data', 'Data file was not created');
        $this->assertFileExists($this->buildDir . '/php-web.data.js', 'JS file was not created');
    }

    public function testCompareFilesXXX()
    {
        // Run both packers
        $this->testPackWithoutLz4();

        // Compare with expected files
        $this->assertEquals(
            file_get_contents($this->buildDir . '/php-web.data'),
            file_get_contents($this->fixturesDir . '/testing2/no-compression.data'),
            'Data files are not equal'
        );

        /*$process = new Process(['prettier', '--write', __DIR__ . '/fixtures/build-php/php-web.data.js']);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'Failed to format JS file');*/


        $this->assertEquals(
            file_get_contents($this->buildDir . '/php-web.data.js'),
            file_get_contents($this->fixturesDir . '/testing2/php-web.data.js'),
            'JS files are not equal'
        );
    }

    public function testCompareFilesLz4()
    {
        $this->testPackWithLz4();

        // Compare with expected files
        $this->assertEquals(
            file_get_contents($this->buildDir . '/php-web.data'),
            file_get_contents($this->fixturesDir . '/testing2/compression.data'),
            'Data files are not equal'
        );

        /*$process = new Process(['prettier', '--write', __DIR__ . '/fixtures/build-php/php-web.data.js']);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'Failed to format JS file');*/

        $this->assertEquals(
            file_get_contents($this->buildDir . '/php-web.data.js'),
            file_get_contents($this->fixturesDir . '/testing2/compression-web.data.js'),
            'JS files are not equal'
        );
    }

    public function testSrcAtDstSyntax()
    {
        // Create build directory if it doesn't exist
        if (!is_dir($this->buildDir)) {
            mkdir($this->buildDir, 0777, true);
        }

        // Create a temporary file outside the current directory
        $tempDir = sys_get_temp_dir() . '/phpx-test-' . uniqid();
        mkdir($tempDir, 0777, true);
        $tempFile = $tempDir . '/test-file.txt';
        file_put_contents($tempFile, 'Hello from temp file!');

        try {
            // Create options with src@dst syntax
            $options = new Options($this->fixturesDir);
            $options->jsOutput = $this->buildDir . '/src-at-dst-test.data.js';
            $options->usePreloadCache = true;
            $options->excludePatterns = ['*/.hidden', '*/*.tmp'];
            $options->supportNode = false;
            $options->exportName = 'createPhpModule';

            // Add file using src@dst syntax
            $options->initialDataFiles[] = new DataFile($tempFile, 'src/test-file.txt', 'preload', true);

            // Create argv array
            $argv = [
                'filepackager.php',
                $this->buildDir . '/src-at-dst-test.data',
                '--preload', $tempFile . '@src/test-file.txt',
                '--js-output=' . $this->buildDir . '/src-at-dst-test.data.js',
                '--use-preload-cache',
                '--exclude', '*/.hidden', '*/*.tmp',
                '--no-node',
                '--export-name=createPhpModule'
            ];

            // Create and run packer
            $webAssemblyPacker = new WebAssemblyPacker($this->eventManager);
            $webAssemblyPacker->pack($options, $argv);

            // Verify files were created
            $this->assertFileExists($this->buildDir . '/src-at-dst-test.data', 'Data file was not created');
            $this->assertFileExists($this->buildDir . '/src-at-dst-test.data.js', 'JS file was not created');

            // Verify the content was packed correctly
            $dataContent = file_get_contents($this->buildDir . '/src-at-dst-test.data');
            $this->assertStringContainsString('Hello from temp file!', $dataContent, 'Temp file content not found in packed data');

        } finally {
            // Clean up temporary file
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
        }
    }
}
