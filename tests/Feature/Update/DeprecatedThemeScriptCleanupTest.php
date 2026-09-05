<?php

use Lvntr\StarterKit\Console\Commands\UpdateCommand;

/**
 * Task 3 (vendor-first Faz 1): the theme build tooling moved from stubs/scripts/
 * into the package (resources/js/theme/, vendor-resident). sk:update must remove
 * the obsolete consumer copies under scripts/ — but only when they are provably
 * unmodified, so a customized script is never silently destroyed.
 *
 * These exercise the command's private decision + registry in isolation
 * (hash-in / bool-out), matching the suite's reflection-test style — no
 * filesystem boot required.
 */

/**
 * @return array<string, list<string>>
 */
function deprecatedModifiablePaths(): array
{
    $reflection = new ReflectionClassConstant(UpdateCommand::class, 'DEPRECATED_MODIFIABLE_PATHS');

    /** @var array<string, list<string>> */
    return $reflection->getValue();
}

/**
 * @param  list<string>  $stockHashes
 */
function deprecatedCopyIsUnmodified(string $currentHash, array $stockHashes, ?string $recordedHash): bool
{
    $method = new ReflectionMethod(UpdateCommand::class, 'deprecatedCopyIsUnmodified');

    return $method->invoke(new UpdateCommand, $currentHash, $stockHashes, $recordedHash);
}

it('registers the obsolete theme build scripts for hash-guarded cleanup', function (): void {
    $paths = deprecatedModifiablePaths();

    expect($paths)->toHaveKeys([
        'scripts/sk-theme-build.mjs',
        'scripts/vite-plugin-sk-theme.mjs',
    ]);

    // Every registered path ships at least one known-stock hash to compare against,
    // otherwise an unmodified copy could never be recognized as safe to delete.
    foreach ($paths as $path => $stockHashes) {
        expect($stockHashes)->not->toBeEmpty("{$path} must declare at least one stock hash");
    }
});

it('treats a copy matching a known shipped version as unmodified (safe to delete)', function (): void {
    $stock = deprecatedModifiablePaths()['scripts/sk-theme-build.mjs'];

    // Any shipped version — including older ones — counts as unmodified.
    foreach ($stock as $hash) {
        expect(deprecatedCopyIsUnmodified($hash, $stock, null))->toBeTrue();
    }
});

it('treats a copy matching the recorded registry hash as unmodified', function (): void {
    $recorded = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    // Hash differs from every stock hash but equals what we recorded at install
    // time → the user has not touched it since → safe to delete.
    expect(deprecatedCopyIsUnmodified($recorded, ['ffffffffffffffffffffffffffffffff'], $recorded))->toBeTrue();
});

it('treats a copy that matches neither stock nor registry as modified (preserve)', function (): void {
    expect(deprecatedCopyIsUnmodified(
        'deadbeefdeadbeefdeadbeefdeadbeef',
        ['ffffffffffffffffffffffffffffffff', 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'],
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    ))->toBeFalse();
});

it('treats a modified copy with no registry record as modified (preserve)', function (): void {
    expect(deprecatedCopyIsUnmodified(
        'deadbeefdeadbeefdeadbeefdeadbeef',
        ['ffffffffffffffffffffffffffffffff'],
        null,
    ))->toBeFalse();
});
