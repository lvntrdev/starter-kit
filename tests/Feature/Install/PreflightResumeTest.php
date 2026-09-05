<?php

use Lvntr\StarterKit\Console\Commands\InstallCommand;

/**
 * Exercise the pure decision helpers behind Task 16's preflight + checkpoint /
 * resume logic on InstallCommand. Each helper is side-effect free (string /
 * array in, scalar out) so it is unit-testable in isolation, mirroring the
 * existing shouldEjectDefaultDomains / buildMergedEnvContent tests.
 */

/**
 * Invoke a private InstallCommand method by name with the given args.
 */
function invokeInstallMethod(string $method, array $args): mixed
{
    $command = new InstallCommand;
    $ref = new ReflectionMethod($command, $method);

    return $ref->invoke($command, ...$args);
}

// ── parseNodeMajorVersion ─────────────────────────────────────────────────────

it('parses the node major version from `node -v` output', function (): void {
    expect(invokeInstallMethod('parseNodeMajorVersion', ["v18.17.0\n"]))->toBe(18);
    expect(invokeInstallMethod('parseNodeMajorVersion', ['v20.5.1']))->toBe(20);
    expect(invokeInstallMethod('parseNodeMajorVersion', ['v16.0.0']))->toBe(16);
    // Some managers print without the leading "v".
    expect(invokeInstallMethod('parseNodeMajorVersion', ['22.1.0']))->toBe(22);
});

it('returns null when the node version string is unrecognizable', function (): void {
    expect(invokeInstallMethod('parseNodeMajorVersion', ['']))->toBeNull();
    expect(invokeInstallMethod('parseNodeMajorVersion', ['not-a-version']))->toBeNull();
    // A bare major with no dot is not a full version — treated as unknown.
    expect(invokeInstallMethod('parseNodeMajorVersion', ['v18']))->toBeNull();
});

// ── stepAlreadyCompleted ──────────────────────────────────────────────────────

it('skips a completed step only when resuming', function (): void {
    $completed = ['Publishing application scaffolding', 'Merging package.json'];

    // Resuming + already completed → skip.
    expect(invokeInstallMethod('stepAlreadyCompleted', ['Merging package.json', true, $completed]))->toBeTrue();
    // Resuming but NOT yet completed → run.
    expect(invokeInstallMethod('stepAlreadyCompleted', ['Running migrations', true, $completed]))->toBeFalse();
    // Not resuming → always run, even if recorded.
    expect(invokeInstallMethod('stepAlreadyCompleted', ['Merging package.json', false, $completed]))->toBeFalse();
});

// ── computeFirstInstall ───────────────────────────────────────────────────────

it('treats a clean run with no hash registry as a first install', function (): void {
    // progressExisted=false, no meta, noHashRegistry=true → first install.
    expect(invokeInstallMethod('computeFirstInstall', [false, [], true]))->toBeTrue();
    // Hash registry present → not first install.
    expect(invokeInstallMethod('computeFirstInstall', [false, [], false]))->toBeFalse();
});

it('inherits the original first-install decision from the checkpoint on resume', function (): void {
    // Checkpoint says it started as a first install → keep ejecting.
    expect(invokeInstallMethod('computeFirstInstall', [true, ['first_install' => true], true]))->toBeTrue();
    // Checkpoint says it was a re-install → stay a re-install.
    expect(invokeInstallMethod('computeFirstInstall', [true, ['first_install' => false], true]))->toBeFalse();
    // Checkpoint without the meta key defaults to NOT first install (safe).
    expect(invokeInstallMethod('computeFirstInstall', [true, [], true]))->toBeFalse();
});

// ── shouldForceOverwrite ──────────────────────────────────────────────────────

it('force-overwrites only on --force or a pristine first install', function (): void {
    // Pristine first install (no checkpoint) → force so preservable paths seed.
    expect(invokeInstallMethod('shouldForceOverwrite', [true, false, false]))->toBeTrue();
    // Explicit --force always wins.
    expect(invokeInstallMethod('shouldForceOverwrite', [false, false, true]))->toBeTrue();
    expect(invokeInstallMethod('shouldForceOverwrite', [false, true, true]))->toBeTrue();
});

it('never force-overwrites a resumed/half-finished install without --force', function (): void {
    // The trap: first_install=true but a checkpoint exists → must NOT force,
    // or a re-run would clobber files the operator edited between attempts.
    expect(invokeInstallMethod('shouldForceOverwrite', [true, true, false]))->toBeFalse();
    // Re-install, no checkpoint, no --force → no force.
    expect(invokeInstallMethod('shouldForceOverwrite', [false, false, false]))->toBeFalse();
});
