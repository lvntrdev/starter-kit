<?php

use Illuminate\Filesystem\Filesystem;
use Lvntr\StarterKit\Console\Commands\InstallCommand;

/**
 * Exercise InstallCommand::domainHasRuntime() — the pre-eject gate AND the
 * post-eject evidence check both route through it. It must distinguish a
 * stub-published BulkActions-only app/Domain/{Name} (no Actions/ → eject should
 * proceed) from one that already holds real runtime (Actions/ present → skip).
 *
 * Pure filesystem probe against the testbench base_path; each test seeds its own
 * fixture under app/Domain/ and tears it down so no real domain dir leaks.
 */

/**
 * Invoke the private domainHasRuntime() on a fresh InstallCommand, with $files
 * initialized (it is assigned lazily inside handle()).
 */
function domainHasRuntime(string $domain): bool
{
    $command = new InstallCommand;

    $filesProperty = new ReflectionProperty($command, 'files');
    $filesProperty->setValue($command, new Filesystem);

    $method = new ReflectionMethod($command, 'domainHasRuntime');

    return (bool) $method->invoke($command, $domain);
}

/**
 * Seed a fixture under app/Domain/{Name} at the testbench base_path and return a
 * cleanup callable that removes the whole app/Domain/{Name} tree afterwards.
 *
 * @param  list<string>  $subdirs  subdirectories to create under the domain
 */
function seedDomainDir(string $domain, array $subdirs): Closure
{
    $fs = new Filesystem;
    $domainDir = base_path("app/Domain/{$domain}");

    foreach ($subdirs as $sub) {
        $fs->makeDirectory($domainDir.'/'.$sub, 0755, true, true);
    }

    return function () use ($fs, $domainDir): void {
        if ($fs->isDirectory($domainDir)) {
            $fs->deleteDirectory($domainDir);
        }
    };
}

// ── Gate passes: a stub-published BulkActions-only dir is NOT runtime ──────────

it('returns false for a stub-published BulkActions-only domain dir', function (): void {
    // This is exactly what step-1 stub publish leaves before eject runs: the
    // domain dir exists but has only BulkActions/, no Actions/. The eject MUST
    // proceed (with --force), so the gate must report "no runtime here".
    $cleanup = seedDomainDir('User', ['BulkActions']);

    $hasRuntime = domainHasRuntime('User');
    $cleanup();

    expect($hasRuntime)->toBeFalse();
});

// ── Gate trips: a populated Actions/ subdir IS runtime ────────────────────────

it('returns true once app/Domain/{Name}/Actions exists', function (): void {
    // A prior eject or hand-authored runtime ships Actions/. The gate must report
    // "runtime present" so the eject is skipped (no --force clobber).
    $cleanup = seedDomainDir('User', ['BulkActions', 'Actions']);

    $hasRuntime = domainHasRuntime('User');
    $cleanup();

    expect($hasRuntime)->toBeTrue();
});

// ── No domain dir at all → not runtime ────────────────────────────────────────

it('returns false when app/Domain/{Name} does not exist', function (): void {
    // Use a name no fixture/stub ever creates so the probe sees a missing dir.
    expect(domainHasRuntime('NonExistentDomain'))->toBeFalse();
});
