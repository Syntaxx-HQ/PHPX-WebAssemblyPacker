<?php

/*
 * This file is part of JoliTypo - a project by JoliCode.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license.
 */

use Castor\Attribute\AsTask;
use Symfony\Component\Process\Process;

use function Castor\context;
use function Castor\fs;
use function Castor\io;
use function Castor\run; // as do_run;
use function Castor\run_php;
use function Castor\watch;

#[AsTask(description: 'Install dependencies')]
function install()
{
    io()->title('Installing dependencies');

    run(['composer', 'install', '--no-dev', '--optimize-autoloader']);
}

#[AsTask(description: 'Update dependencies')]
function update()
{
    io()->title('Installing dependencies');

    run(['composer', 'update', '--no-dev', '--optimize-autoloader']);
}

#[AsTask('wasm:build', description: 'Build the wasm-php binary')]
function wasm_build()
{
    io()->title('Building wasm-php binary');

    run(['docker', 'buildx', 'bake']);
}

#[AsTask('wasm:pack:emscripten', description: 'Pack custom code')]
function wasm_pack()
{
    io()->title('Packing custom code');


    run(['docker', 'run',
         '-v', __DIR__ . '/test_dir:/app',
         '-v', __DIR__ . '/build:/dist/build',
         '-w', '/dist',
         'emscripten/emsdk:4.0.6',
            'python3',
                '/emsdk/upstream/emscripten/tools/file_packager.py',
                'build/php-web.data',
                '--use-preload-cache',
                //'--lz4',
                '--preload', '/app',
                '--js-output=build/php-web.data.js',
                '--no-node',
                '--exclude',
                    '*/.hidden',
                    '*/*.tmp',
                '--export-name=createPhpModule',
    ]);
}

#[AsTask('wasm:pack:purephp', description: 'Pack custom code')]
function wasm_pack_purephp()
{
    io()->title('Packing custom code Pure PHP');

    run(['php', 'file_packager.php',
         'build-php/php-web.data',
         '--preload', __DIR__ . '/test_dir',
         '--js-output=build-php/php-web.data.js',
         //'--lz4',
         '--use-preload-cache',
         '--exclude',
         '*/.hidden',
         '*/*.tmp',
         '--no-node',
         '--export-name=createPhpModule',
    ]);
}

//cmp -s file1.txt file2.txt
#[AsTask('wasm:pack:compare:plain', description: 'Compare files')]
function compare_files()
{
    wasm_pack_purephp();
    wasm_pack();
    
    run(['prettier', '--write', __DIR__ . '/build-php/php-web.data.js']);
    //run(['prettier', '--write', __DIR__ . '/build/php-web.data.js']);

    //$result = run(['cmp', '-s', __DIR__ . '/build-php/php-web.data', __DIR__ . '/build/php-web.data']);
    $result = run(['cmp', '-s', __DIR__ . '/build-php/php-web.data', __DIR__ . '/testing/no-compression.data']);
    if (!$result->isSuccessful()) {
        io()->error('Files are not equal');
        return false;
    }

    $result = run(['cmp', '-s', __DIR__ . '/build-php/php-web.data.js', __DIR__ . '/testing/php-web.data.js']);
    if (!$result->isSuccessful()) {
        io()->error('Files are not equal');
        return false;
    }

    return true;
}
