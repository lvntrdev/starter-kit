<?php

use Lvntr\StarterKit\Console\Commands\InstallCommand;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * Invoke InstallCommand::shouldEjectDefaultDomains() in isolation.
 * Pure boolean logic — no filesystem, no eject side-effects.
 */
function shouldEject(bool $isFirstInstall, array $options = []): bool
{
    $command = new InstallCommand;

    // InstallCommand::shouldEjectDefaultDomains() reads $this->option('without-eject').
    // We resolve the underlying Symfony InputInterface so the method can call option().
    $input = new ArrayInput($options, $command->getDefinition());
    $command->setInput($input);

    $method = new ReflectionMethod($command, 'shouldEjectDefaultDomains');

    return (bool) $method->invoke($command, $isFirstInstall);
}

// ── Decision unit: first install, no flag → eject ─────────────────────────

it('returns true on first install without --without-eject', function (): void {
    expect(shouldEject(isFirstInstall: true))->toBeTrue();
});

// ── Decision unit: --without-eject suppresses eject ───────────────────────

it('returns false on first install when --without-eject is passed', function (): void {
    expect(shouldEject(isFirstInstall: true, options: ['--without-eject' => true]))->toBeFalse();
});

// ── Decision unit: re-install → always false ──────────────────────────────

it('returns false on re-install regardless of --without-eject', function (): void {
    expect(shouldEject(isFirstInstall: false))->toBeFalse();
    expect(shouldEject(isFirstInstall: false, options: ['--without-eject' => true]))->toBeFalse();
});
