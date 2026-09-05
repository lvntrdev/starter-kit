<?php

use Lvntr\StarterKit\Console\Commands\InstallCommand;

/**
 * Invoke the command's private .gitignore merge logic in isolation.
 * Pure string-in / string-out — no filesystem or app boot required.
 */
function mergeGitignore(string $existing): string
{
    $command = new InstallCommand;
    $method = new ReflectionMethod($command, 'buildGitignoreContent');

    return $method->invoke($command, $existing);
}

it('writes the full ignore set into an empty .gitignore', function (): void {
    $result = mergeGitignore('');

    expect($result)
        ->toContain('/storage/starter-kit/')
        ->toContain('/resources/js/wayfinder/')
        ->toContain('/bootstrap/ssr')
        ->toContain('.env')
        ->toContain('!.env.example')
        ->toContain('.DS_Store')
        ->toContain('/storage/*.key')
        ->toContain('.claude/settings.local.json');
});

it('does not leak the private ai-kit toolchain into shipped comments', function (): void {
    expect(strtolower(mergeGitignore('')))->not->toContain('ai-kit');
});

it('preserves existing entries and only appends what is missing', function (): void {
    $existing = "/vendor\n/node_modules\n\n# my custom block\n/my-secret-dir\n";

    $result = mergeGitignore($existing);

    // User/Laravel lines are kept verbatim.
    expect($result)
        ->toContain('# my custom block')
        ->toContain('/my-secret-dir');

    // Already-present entries are never duplicated.
    expect(substr_count($result, '/vendor'))->toBe(1);
    expect(substr_count($result, '/node_modules'))->toBe(1);

    // Missing kit entries are added.
    expect($result)->toContain('/storage/starter-kit/');
});

it('is idempotent — a second merge changes nothing', function (): void {
    $once = mergeGitignore("/vendor\n/node_modules\n");
    $twice = mergeGitignore($once);

    expect($twice)->toBe($once);
});
