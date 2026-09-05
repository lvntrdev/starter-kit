<?php

use Lvntr\StarterKit\Console\Commands\InstallCommand;
use Lvntr\StarterKit\Console\Commands\UpdateCommand;

/**
 * Invoke the command's private locally-reproduced-artifact filter in isolation.
 * Pure string-in / bool-out — no filesystem or app boot required.
 *
 * Regression guard: composer export-ignore strips build artifacts from the dist
 * tarball, but path / working-copy installs read stubs straight from disk, so
 * sk:update must skip them itself or it copies stale build output into the app.
 */
function updateIgnoresStubFile(string $relativePath): bool
{
    $command = new UpdateCommand;
    $method = new ReflectionMethod($command, 'isIgnoredStubFile');

    return $method->invoke($command, $relativePath);
}

it('skips locally-reproduced artifacts on update', function (string $path): void {
    expect(updateIgnoresStubFile($path))->toBeTrue();
})->with([
    'node_modules/.package-lock.json',
    'node_modules/.vite/results.json',
    'public/build/manifest.json',
    'bootstrap/ssr/ssr.js',
    'resources/js/routes/index.ts',
    'auto-imports.d.ts',
    'components.d.ts',
    'package-lock.json',
    'vendor/autoload.php',
    'resources/js/.DS_Store',
]);

it('does not skip real stub source files on update', function (string $path): void {
    expect(updateIgnoresStubFile($path))->toBeFalse();
})->with([
    'resources/js/env.d.ts',          // hand-written, intentionally NOT ignored
    'resources/js/pages/Admin/Dashboard.vue',
    'app/Http/Controllers/Admin/UserController.php',
    'config/permission-resources.php',
    'package.json',
]);

it('stays in lockstep with InstallCommand for the shared artifact set', function (string $path): void {
    $install = new ReflectionMethod(new InstallCommand, 'isIgnoredStubFile');

    expect(updateIgnoresStubFile($path))->toBe($install->invoke(new InstallCommand, $path));
})->with([
    'node_modules/foo.js',
    'public/build/app.js',
    'bootstrap/ssr/ssr.js',
    'resources/js/routes/index.ts',
    'auto-imports.d.ts',
    'components.d.ts',
    'package-lock.json',
    'resources/js/env.d.ts',
    'app/Models/User.php',
]);
