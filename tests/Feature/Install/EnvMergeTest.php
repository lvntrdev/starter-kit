<?php

use Illuminate\Filesystem\Filesystem;
use Lvntr\StarterKit\Console\Commands\InstallCommand;

/**
 * Invoke the command's private .env merge logic in isolation.
 * Pure string-in / string-out — no filesystem or app boot required.
 */
function mergeEnv(string $env, string $example, bool $isFirstInstall = false): ?string
{
    $command = new InstallCommand;
    $method = new ReflectionMethod($command, 'buildMergedEnvContent');

    return $method->invoke($command, $env, $example, $isFirstInstall);
}

/**
 * Invoke the command's private atomic .env writer against a real temp path.
 * $this->files is assigned in handle(), so it is injected here by reflection.
 */
function writeEnvAtomically(string $path, string $content): void
{
    $command = new InstallCommand;

    $files = new ReflectionProperty($command, 'files');
    $files->setValue($command, new Filesystem);

    $method = new ReflectionMethod($command, 'putEnvAtomically');
    $method->invoke($command, $path, $content);
}

it('appends keys present in the example but missing from .env', function (): void {
    $env = "APP_NAME=Acme\nAPP_KEY=base64:abc\n";
    $example = "APP_NAME=\nAPP_KEY=\nCACHE_STORE=redis\nREDIS_HOST=127.0.0.1\n";

    $result = mergeEnv($env, $example);

    expect($result)
        ->toContain('CACHE_STORE=redis')
        ->toContain('REDIS_HOST=127.0.0.1')
        ->toContain('# ---- Lvntr Starter Kit ----');
});

it('never overwrites an existing key or its value', function (): void {
    $env = "APP_NAME=Acme\nDB_PASSWORD=supersecret\n";
    $example = "APP_NAME=\nDB_PASSWORD=\nCACHE_STORE=redis\n";

    $result = mergeEnv($env, $example);

    // User value preserved, the key appears exactly once.
    expect($result)->toContain('DB_PASSWORD=supersecret');
    expect(substr_count($result, 'DB_PASSWORD='))->toBe(1);
    expect($result)->not->toContain('supersecret'."\nDB_PASSWORD=");
});

it('returns null when the .env already has every example key', function (): void {
    $env = "APP_NAME=Acme\nCACHE_STORE=redis\n";
    $example = "APP_NAME=\nCACHE_STORE=file\n";

    expect(mergeEnv($env, $example))->toBeNull();
});

it('ignores comment and blank lines in the example', function (): void {
    $env = "APP_NAME=Acme\n";
    $example = "APP_NAME=\n\n# a comment\n# STARTER_KIT_DATATABLE_PER_PAGE=10\n";

    // The only non-comment key (APP_NAME) is already present → nothing to add.
    expect(mergeEnv($env, $example))->toBeNull();
});

/*
| First-install-only keys.
|
| The merge path runs on a RE-install; ensureEnvFile() copies .env.example
| wholesale on a first install and never reaches here. So a key skipped below
| lands in a brand-new project and in no existing one — which is the whole
| mechanism behind "a fresh app is fail-closed, an upgraded app is untouched".
*/

it('never merges a first-install-only key into an existing .env', function (): void {
    $env = "APP_NAME=Acme\n";
    $example = "APP_NAME=\nSTARTER_KIT_ALLOW_UNRESOLVED_ROUTES=false\n";

    // The only other example key is already present, so a leaked key would be
    // the sole reason this returns non-null.
    expect(mergeEnv($env, $example))->toBeNull();
});

it('does not let a first-install-only key drag other missing keys along', function (): void {
    $env = "APP_NAME=Acme\n";
    $example = "APP_NAME=\nSTARTER_KIT_ALLOW_UNRESOLVED_ROUTES=false\nCACHE_STORE=redis\n";

    $result = mergeEnv($env, $example);

    expect($result)
        ->toContain('CACHE_STORE=redis')
        ->not->toContain('STARTER_KIT_ALLOW_UNRESOLVED_ROUTES');
});

it('leaves an operator-set value for a first-install-only key alone', function (): void {
    $env = "APP_NAME=Acme\nSTARTER_KIT_ALLOW_UNRESOLVED_ROUTES=true\n";
    $example = "APP_NAME=\nSTARTER_KIT_ALLOW_UNRESOLVED_ROUTES=false\n";

    // Skipping happens before the "is it missing?" test, so an app that
    // deliberately opted back out keeps its own value either way.
    expect(mergeEnv($env, $example))->toBeNull();
});

it('ships the fresh-install default as false in the example env', function (): void {
    $example = file_get_contents(dirname(__DIR__, 3).'/stubs/.env.example');

    // A fresh install copies this file verbatim, so the line here IS the
    // fresh-install default. Commenting it out would silently undo the feature.
    expect($example)->toContain("\nSTARTER_KIT_ALLOW_UNRESOLVED_ROUTES=false");
});

it('copies missing lines verbatim, keeping inline defaults', function (): void {
    $env = "APP_NAME=Acme\n";
    $example = "APP_NAME=\nSESSION_DOMAIN=null\nBCRYPT_ROUNDS=12\n";

    $result = mergeEnv($env, $example);

    expect($result)
        ->toContain('SESSION_DOMAIN=null')
        ->toContain('BCRYPT_ROUNDS=12');
});

/*
| First-install seeding WITHOUT an overwrite.
|
| ensureEnvFile() no longer copies .env.example over an existing file on a first
| install — that copy was the credential-loss path (APP_KEY, DB_PASSWORD). The
| first-install intent now rides the flag below: the same keys a brand-new
| project used to receive by wholesale copy are seeded through the merge, and
| only where they are absent.
*/

it('seeds first-install-only keys when the run is a genuine first install', function (): void {
    $env = "APP_NAME=Acme\n";
    $example = "APP_NAME=\nSTARTER_KIT_ALLOW_UNRESOLVED_ROUTES=false\n";

    $result = mergeEnv($env, $example, isFirstInstall: true);

    expect($result)->toContain('STARTER_KIT_ALLOW_UNRESOLVED_ROUTES=false');
});

it('seeds the dedicated-encryption key pair on a genuine first install', function (): void {
    $env = "APP_NAME=Acme\n";
    $example = "APP_NAME=\nDATA_ENCRYPTION_KEY=\nDATA_ENCRYPTION_PREVIOUS_KEYS=\n";

    $result = mergeEnv($env, $example, isFirstInstall: true);

    // A wholesale copy used to place these on a fresh install; the merge has to
    // reproduce that, or ensureDataEncryptionKey() lands in a file with no slot.
    expect($result)
        ->toContain('DATA_ENCRYPTION_KEY=')
        ->toContain('DATA_ENCRYPTION_PREVIOUS_KEYS=');
});

it('never rewrites an existing value even on a first install', function (): void {
    // The exact shape a `composer create-project` app has when sk:install is
    // first run: a real .env with the operator's own APP_KEY and DB_PASSWORD.
    $env = "APP_KEY=base64:REAL\nDB_PASSWORD=supersecret\nSTARTER_KIT_ALLOW_UNRESOLVED_ROUTES=true\n";
    $example = "APP_KEY=\nDB_PASSWORD=\nSTARTER_KIT_ALLOW_UNRESOLVED_ROUTES=false\nCACHE_STORE=redis\n";

    $result = mergeEnv($env, $example, isFirstInstall: true);

    expect($result)->toContain('APP_KEY=base64:REAL');
    expect($result)->toContain('DB_PASSWORD=supersecret');
    expect($result)->toContain('STARTER_KIT_ALLOW_UNRESOLVED_ROUTES=true');
    expect(substr_count($result, 'APP_KEY='))->toBe(1);
    expect(substr_count($result, 'DB_PASSWORD='))->toBe(1);
    expect(substr_count($result, 'STARTER_KIT_ALLOW_UNRESOLVED_ROUTES='))->toBe(1);
    expect($result)->toContain('CACHE_STORE=redis');
});

/*
| Atomic .env writes.
|
| A plain put() opens the real file with O_TRUNC, so a run interrupted between
| the truncate and the write leaves an empty .env and the credentials are gone.
| Every .env writer in InstallCommand goes through putEnvAtomically() instead.
*/

it('replaces .env contents and leaves no temporary file behind', function (): void {
    $dir = sys_get_temp_dir().'/sk-env-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    $path = $dir.'/.env';
    file_put_contents($path, "APP_KEY=base64:OLD\n");

    writeEnvAtomically($path, "APP_KEY=base64:OLD\nCACHE_STORE=redis\n");

    expect(file_get_contents($path))->toBe("APP_KEY=base64:OLD\nCACHE_STORE=redis\n");
    // A leftover temp file would still hold a full copy of the credentials.
    expect(glob($dir.'/.env.sk-tmp-*'))->toBe([]);

    array_map('unlink', glob($dir.'/.env*') ?: []);
    rmdir($dir);
});

it('preserves the mode of an existing .env', function (): void {
    $dir = sys_get_temp_dir().'/sk-env-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    $path = $dir.'/.env';
    file_put_contents($path, "APP_KEY=base64:OLD\n");
    chmod($path, 0640);
    clearstatcache(true, $path);

    writeEnvAtomically($path, "APP_KEY=base64:OLD\nCACHE_STORE=redis\n");
    clearstatcache(true, $path);

    expect(fileperms($path) & 0777)->toBe(0640);

    array_map('unlink', glob($dir.'/.env*') ?: []);
    rmdir($dir);
});

it('creates a new .env readable only by its owner', function (): void {
    $dir = sys_get_temp_dir().'/sk-env-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    $path = $dir.'/.env';

    writeEnvAtomically($path, "APP_KEY=\n");
    clearstatcache(true, $path);

    // The file carries DB credentials and APP_KEY from its first byte; a
    // permissive umask must not decide who can read them.
    expect(fileperms($path) & 0777)->toBe(0600);

    array_map('unlink', glob($dir.'/.env*') ?: []);
    rmdir($dir);
});

it('writes through a symlinked .env instead of replacing the link', function (): void {
    // Zero-downtime deployers (Envoyer, Deployer, Capistrano) symlink the
    // release's .env at one shared file. Replacing the link orphans the shared
    // credentials and hands this release a private copy the next deploy never
    // sees, so the write has to follow the link.
    $dir = sys_get_temp_dir().'/sk-env-'.bin2hex(random_bytes(6));
    mkdir($dir.'/shared', 0700, true);
    mkdir($dir.'/release', 0700, true);

    $shared = $dir.'/shared/.env';
    $link = $dir.'/release/.env';
    file_put_contents($shared, "APP_KEY=base64:SHARED\n");
    symlink($shared, $link);

    writeEnvAtomically($link, "APP_KEY=base64:SHARED\nCACHE_STORE=redis\n");
    clearstatcache();

    expect(is_link($link))->toBeTrue();
    expect(file_get_contents($shared))->toBe("APP_KEY=base64:SHARED\nCACHE_STORE=redis\n");
    expect(glob($dir.'/shared/.env.sk-tmp-*'))->toBe([]);
    expect(glob($dir.'/release/.env.sk-tmp-*'))->toBe([]);

    unlink($link);
    unlink($shared);
    rmdir($dir.'/release');
    rmdir($dir.'/shared');
    rmdir($dir);
});
