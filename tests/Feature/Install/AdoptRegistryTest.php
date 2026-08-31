<?php

use Lvntr\StarterKit\Console\Commands\InstallCommand;
use Lvntr\StarterKit\StarterKitServiceProvider;

require_once __DIR__.'/InstallDecisionHarness.php';

/**
 * `sk:install --adopt` × the "never overwrite a file we did not publish" guard.
 *
 * The question this file answers, because it is the one that decides whether
 * --adopt is a recovery path or a data-loss path: --adopt writes a registry
 * record for EVERY stub whose target merely EXISTS on disk, without ever
 * reading that target's contents. A consumer file that the kit has never
 * published therefore stops being UNTRACKED — and UNTRACKED is precisely the
 * outcome that publishDirectory() turns into a preserve on a re-install.
 *
 * THE VERDICT: no, the protection is not lost, and the comparison that saves it
 * is the ORDER inside {@see ComparesPublishedStubs::decidePublishedStub()} —
 * rule 6 (`recorded === stub` → UP_TO_DATE → preserve) is evaluated BEFORE
 * rule 7 (`recorded === target` → WRITE). --adopt records md5 of the STUB, so:
 *
 *   - same package version → recorded === stub → UP_TO_DATE → preserved
 *   - later package version → recorded is an OLD stub hash, equal to neither
 *     the new stub nor the consumer's bytes → MODIFIED → preserved
 *   - the only route to WRITE is recorded === target, which for an adopt record
 *     means the bytes on disk ARE the bytes the kit shipped at adopt time —
 *     so the refresh is provably lossless, not a loss
 *
 * The one thing --adopt genuinely cannot see is a file the consumer DELETED
 * before adopting: it is absent, so it gets no record and a later sk:install
 * republishes it. That is a restore, not a loss, and --adopt reports it up
 * front as its `missing` count. Asserted below so the limit stays documented.
 *
 * Everything here drives the decision directly. A real end-to-end sk:install
 * reaches npm/composer (see InstallDecisionHarness.php), and publishDirectory()
 * takes its source and destination as arguments while reading the registry from
 * config('starter-kit.published_hashes') — so every case runs on throwaway
 * trees. The `ici*` helpers come from the harness via require_once; the two
 * `adr*` helpers below are local because global scope is shared across files.
 */
beforeEach(function (): void {
    $GLOBALS['ici_trees'] = [];
});

afterEach(function (): void {
    foreach ($GLOBALS['ici_trees'] ?? [] as $dir) {
        iciRemove($dir);
    }

    $GLOBALS['ici_trees'] = [];
});

/**
 * Seed the registry exactly the way --adopt leaves it, and make it
 * AUTHORITATIVE (the file exists, so isFirstInstall() is false).
 *
 * @param  array<string, string>  $records
 */
function adrRegistry(array $records): void
{
    $path = iciBoot().'/hashes.json';
    file_put_contents($path, json_encode(['_format' => 'v2'] + $records));

    config(['starter-kit.published_hashes' => $path]);
}

function adrPublish(InstallCommand $command, string $source, string $destination, bool $force = false): void
{
    iciInvoke($command, 'publishDirectory', [$source, $destination, $force]);
}

const ADR_PATH = 'app/Http/Controllers/Admin/DemoController.php';

// ── What --adopt actually writes ───────────────────────────────────────────

it('records the SHIPPED stub hash for an adopted target, never the bytes on disk', function (): void {
    // The mechanic under scrutiny, asserted against the real stubs tree: the
    // target holds the consumer's own content, and the record that lands is
    // still md5 of the stub. If this ever flipped to hashing the target, every
    // adopted file would read as "untouched since we shipped it" (rule 7) and
    // the next release would overwrite the lot.
    iciBoot(['config/settings.php' => 'CONSUMER OWN FILE']);

    [$command] = iciCommand();
    $registry = iciInvoke($command, 'buildStubHashRegistry');

    $shipped = md5_file(StarterKitServiceProvider::stubsPath('config/settings.php'));

    expect($registry['hashes']['config/settings.php'])
        ->toBe($shipped)
        ->not->toBe(md5('CONSUMER OWN FILE'))
        ->and($registry['adopted'])->toBe(1);
});

it('leaves an absent stub target out of the registry entirely', function (): void {
    // The documented `missing` count. No record means a later sk:install treats
    // the path as new and publishes it — --adopt cannot distinguish "deliberately
    // deleted" from "never installed", so it claims neither.
    iciBoot(['config/settings.php' => 'CONSUMER OWN FILE']);

    [$command] = iciCommand();
    $registry = iciInvoke($command, 'buildStubHashRegistry');

    expect($registry['hashes'])->not->toHaveKey('config/permission-resources.php')
        ->and($registry['missing'])->toBeGreaterThan(0);
});

// ── The consequence for the publish loop ───────────────────────────────────

it('preserves a consumer file an adopt record claims, on the same package version', function (): void {
    // Adopted while the package shipped 'STUB v1', re-run on the same version:
    // recorded === stub fires first (UP_TO_DATE), so the consumer's file stays.
    $source = iciBoot([ADR_PATH => 'STUB v1']);
    $destination = iciBoot([ADR_PATH => 'CONSUMER OWN FILE']);
    adrRegistry([ADR_PATH => md5('STUB v1')]);

    [$command] = iciCommand();
    adrPublish($command, $source, $destination);

    expect(file_get_contents($destination.'/'.ADR_PATH))->toBe('CONSUMER OWN FILE')
        ->and(iciProperty($command, 'preserved'))->toContain(ADR_PATH)
        ->and(iciProperty($command, 'published'))->not->toContain(ADR_PATH);
});

it('preserves a consumer file an adopt record claims, after the package moves on', function (): void {
    // THE case the verdict turns on. The adopt record is now an OLD stub hash:
    // it matches neither the shipped stub nor the bytes on disk, so the rule
    // lands on MODIFIED — new version AND a local difference — and preserves.
    $source = iciBoot([ADR_PATH => 'STUB v2']);
    $destination = iciBoot([ADR_PATH => 'CONSUMER OWN FILE']);
    adrRegistry([ADR_PATH => md5('STUB v1')]);

    [$command] = iciCommand();
    adrPublish($command, $source, $destination);

    expect(file_get_contents($destination.'/'.ADR_PATH))->toBe('CONSUMER OWN FILE')
        ->and(iciProperty($command, 'preserved'))->toContain(ADR_PATH)
        ->and(iciProperty($command, 'published'))->not->toContain(ADR_PATH);
});

it('refreshes an adopted file only when its bytes are the ones the kit shipped', function (): void {
    // The single route from an adopt record to a write, and why it is not a
    // loss: the target is byte-for-byte the stub as shipped at adopt time, so
    // what gets replaced is the kit's own content, not the consumer's.
    $source = iciBoot([ADR_PATH => 'STUB v2']);
    $destination = iciBoot([ADR_PATH => 'STUB v1']);
    adrRegistry([ADR_PATH => md5('STUB v1')]);

    [$command] = iciCommand();
    adrPublish($command, $source, $destination);

    expect(file_get_contents($destination.'/'.ADR_PATH))->toBe('STUB v2')
        ->and(iciProperty($command, 'published'))->toContain(ADR_PATH)
        ->and(iciProperty($command, 'preserved'))->toBeEmpty();
});

it('keeps --force as the documented way through an adopt record', function (): void {
    // --adopt is the recovery path out of the fail-closed stop; the preserve it
    // produces must never be a dead end. --force still takes the package
    // version, exactly as it does for MODIFIED and UNTRACKED.
    $source = iciBoot([ADR_PATH => 'STUB v2']);
    $destination = iciBoot([ADR_PATH => 'CONSUMER OWN FILE']);
    adrRegistry([ADR_PATH => md5('STUB v1')]);

    [$command] = iciCommand();
    adrPublish($command, $source, $destination, force: true);

    expect(file_get_contents($destination.'/'.ADR_PATH))->toBe('STUB v2')
        ->and(iciProperty($command, 'published'))->toContain(ADR_PATH)
        ->and(iciProperty($command, 'preserved'))->toBeEmpty();
});

// ── The rule itself, stated as the invariant --adopt depends on ────────────

it('never lets an adopt record authorise a write over bytes the kit never shipped', function (
    string $shippedNow,
    string $onDisk,
    string $shippedAtAdopt,
): void {
    // An adopt record is ALWAYS md5 of some stub. So long as the bytes on disk
    // are not one of the kit's own, no combination reaches STUB_WRITE without
    // --force. This is the guard rule 6-before-rule-7 provides; reordering
    // those two clauses fails here rather than in production.
    [$command] = iciCommand();

    $decision = iciInvoke($command, 'decidePublishedStub', [
        md5($shippedNow),
        md5($onDisk),
        md5($shippedAtAdopt),
        false,
    ]);

    expect($decision)->toBeIn([
        InstallCommand::STUB_UP_TO_DATE,
        InstallCommand::STUB_MODIFIED,
    ]);
})->with([
    'adopted on the current version' => ['STUB v2', 'CONSUMER OWN FILE', 'STUB v2'],
    'adopted one release back' => ['STUB v2', 'CONSUMER OWN FILE', 'STUB v1'],
    'adopted several releases back' => ['STUB v3', 'CONSUMER OWN FILE', 'STUB v1'],
]);

it('is never weaker than the no-record path it replaces', function (): void {
    // The comparison that makes the verdict a verdict: with an AUTHORITATIVE
    // registry, an untracked consumer file is preserved (STUB_UNTRACKED), and
    // an adopt record over the same file is preserved too. --adopt changes
    // which outcome fires, not whether the file survives.
    [$command] = iciCommand();

    $untracked = iciInvoke($command, 'decidePublishedStub', [
        md5('STUB v2'), md5('CONSUMER OWN FILE'), null, false,
    ]);

    $adopted = iciInvoke($command, 'decidePublishedStub', [
        md5('STUB v2'), md5('CONSUMER OWN FILE'), md5('STUB v1'), false,
    ]);

    expect($untracked)->toBe(InstallCommand::STUB_UNTRACKED)
        ->and($adopted)->toBe(InstallCommand::STUB_MODIFIED)
        // Both are preserve outcomes in publishDirectory(); neither writes.
        ->and([$untracked, $adopted])->not->toContain(InstallCommand::STUB_WRITE);
});
