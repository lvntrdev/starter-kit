<?php

use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory as ComponentsFactory;
use Illuminate\Filesystem\Filesystem;
use Lvntr\StarterKit\Console\Commands\InstallCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Shared harness for the sk:install tests that drive a DECISION rather than a
 * run.
 *
 * Several of `sk:install`'s decisions cannot be reached end to end: with
 * `--no-interaction` the command walks into `npm install` and
 * `composer dump-autoload`, and the destructive migration branch sits behind
 * laravel/prompts' `select()`/`text()`, which this project's version (^0.3.0,
 * no `Prompt::fake()`) cannot drive without a real TTY. So those tests build a
 * half-wired command and call the private decision method directly.
 *
 * The helpers live HERE, in a plain `require_once` file, and not at the top of
 * one test file: Pest declares a test file's helpers at global scope for the
 * whole process, so a sibling file could call them — but only while both files
 * are collected in the same run. `vendor/bin/pest <one-file>` loaded only that
 * file and every helper call became an undefined-function fatal, which broke
 * exactly the targeted run the project's verification discipline asks for.
 *
 * The `ici` prefix is kept for the same reason it was chosen: global scope is
 * shared, and bare names collide across files.
 */

/**
 * An InstallCommand with just enough console wiring for the closing summary to
 * render, pointed at a throwaway application tree.
 *
 * @param  array<string, mixed>  $properties
 * @return array{0: InstallCommand, 1: BufferedOutput}
 */
function iciCommand(array $properties = []): array
{
    $command = new InstallCommand;

    $output = new BufferedOutput;
    $input = new ArrayInput([], $command->getDefinition());
    $style = new OutputStyle($input, $output);
    $command->setInput($input);
    $command->setOutput($style);

    foreach ($properties + [
        'files' => new Filesystem,
        'components' => new ComponentsFactory($style),
    ] as $property => $value) {
        $ref = new ReflectionProperty($command, $property);
        $ref->setValue($command, $value);
    }

    return [$command, $output];
}

function iciProperty(InstallCommand $command, string $property): mixed
{
    $ref = new ReflectionProperty($command, $property);

    return $ref->getValue($command);
}

function iciInvoke(InstallCommand $command, string $method, array $args = []): mixed
{
    $ref = new ReflectionMethod($command, $method);

    return $ref->invoke($command, ...$args);
}

/**
 * A throwaway application tree, with base_path() (and therefore storage_path())
 * repointed at it so nothing leaks into the testbench skeleton.
 *
 * @param  array<string, string>  $tree
 */
function iciBoot(array $tree = []): string
{
    $dir = sys_get_temp_dir().'/sk-incomplete-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);

    foreach ($tree as $relative => $contents) {
        $path = $dir.'/'.$relative;
        is_dir(dirname($path)) || mkdir(dirname($path), 0700, true);
        file_put_contents($path, $contents);
    }

    app()->setBasePath($dir);
    $GLOBALS['ici_trees'][] = $dir;

    return $dir;
}

function iciRemove(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($dir);
}
