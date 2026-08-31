<?php

/*
|--------------------------------------------------------------------------
| encryption:key — the only code path in the kit that writes key material
|--------------------------------------------------------------------------
|
| This file locks the properties whose failure mode is UNRECOVERABLE data, not
| a red test in CI:
|
|   1. WRITE ORDER. The old primary lands in DATA_ENCRYPTION_PREVIOUS_KEYS in a
|      write that is FLUSHED TO DISK BEFORE the new DATA_ENCRYPTION_KEY is
|      written. The end state of the two possible orders is IDENTICAL, so an
|      end-state assertion proves nothing — the ordering is only observable
|      while the command runs, so it is asserted on the sequence of write
|      payloads. A crash between the two writes must leave an .env that still
|      names the key the data was encrypted with.
|   2. APP_KEY IS NEVER TOUCHED. Byte-identical before and after, on every path.
|      It is the fallback that keeps every pre-adoption row readable; a rewrite
|      that "only reformatted" it is silent data loss.
|   3. --show WRITES NOTHING. It is the sanctioned printing path, and an
|      accidental write there would rotate a production key from a command that
|      reads as read-only.
|   4. A PRODUCTION-LOOKING ENVIRONMENT REFUSES without --force.
|
| Every test runs against a TEMP .env fixture: the app base path is redirected
| into a scratch directory, so nothing here can reach the testbench skeleton's
| own .env or the repository's.
|
| Helpers carry an `ekc` prefix — a Pest file declares its helpers at global
| scope for the whole process, so bare names collide across files.
|
*/

use Illuminate\Filesystem\Filesystem;
use Lvntr\StarterKit\Support\Encryption\DataEncrypterFactory;

require_once __DIR__.'/EncryptionKeyHarness.php';

beforeEach(function (): void {
    $this->ekcBasePath = sys_get_temp_dir().'/sk-encryption-key-'.bin2hex(random_bytes(6));
    mkdir($this->ekcBasePath, 0755, true);

    // Redirect base_path() away from the testbench skeleton so no test in this
    // file can write an .env anyone else reads.
    app()->setBasePath($this->ekcBasePath);

    config([
        'starter-kit.encryption.cipher' => null,
        'app.cipher' => 'AES-256-CBC',
    ]);
});

afterEach(function (): void {
    $path = $this->ekcBasePath ?? null;

    if (is_string($path) && is_dir($path)) {
        foreach ((array) glob($path.'/{,.}*', GLOB_BRACE) as $entry) {
            if (is_string($entry) && is_file($entry)) {
                @unlink($entry);
            }
        }

        @rmdir($path);
    }
});

/*
|--------------------------------------------------------------------------
| 1. Write order — the safety property
|--------------------------------------------------------------------------
*/

it('writes the previous-key list to disk BEFORE the new primary key', function (): void {
    $old = ekcKey('old-dedicated');

    ekcFixture("APP_NAME=Test\nAPP_KEY=".ekcKey('app')."\nDATA_ENCRYPTION_KEY={$old}\n");

    $result = ekcRun();

    expect($result['status'])->toBe(0);

    $after = ekcEnvContents();
    $new = ekcRead($after, DataEncrypterFactory::PRIMARY_ENV_KEY);

    expect($new)->not->toBeNull()
        ->and($new)->not->toBe($old);

    // TWO flushes, not one merged write: a single write cannot express the
    // ordering guarantee at all.
    expect($result['files']->writes)->toHaveCount(2);

    [$first, $second] = $result['files']->writes;

    // Write #1 — the old key is already preserved, and the primary is STILL the
    // old key. This is the state a crash between the two writes leaves behind,
    // and it is fully readable.
    expect(ekcRead($first, DataEncrypterFactory::PREVIOUS_ENV_KEY))->toBe($old)
        ->and(ekcRead($first, DataEncrypterFactory::PRIMARY_ENV_KEY))->toBe($old)
        ->and($first)->not->toContain((string) $new);

    // Write #2 — only now does the new primary appear, and the preserved list
    // survives it.
    expect(ekcRead($second, DataEncrypterFactory::PRIMARY_ENV_KEY))->toBe($new)
        ->and(ekcRead($second, DataEncrypterFactory::PREVIOUS_ENV_KEY))->toBe($old);

    // End state matches the last write.
    expect($after)->toBe($second);
});

it('prepends the retired key ahead of the keys already in the list, without duplicating', function (): void {
    $old = ekcKey('old-dedicated');
    $older = ekcKey('older');

    // The list is QUOTED because it carries spaces, and phpdotenv rejects
    // whitespace in an unquoted value — an unquoted form here would be an .env
    // that Laravel itself cannot boot, so the command is right to refuse it and
    // the fixture would be testing an unreachable state. The spaces, the blank
    // entry and the duplicate are the point of the test and all survive quoting.
    ekcFixture(
        'APP_KEY='.ekcKey('app')."\n"
        ."DATA_ENCRYPTION_KEY={$old}\n"
        ."DATA_ENCRYPTION_PREVIOUS_KEYS=\"{$older}, {$old} ,,{$older}\"\n"
    );

    expect(ekcRun()['status'])->toBe(0);

    // Newest retired key first (most likely decrypt hit tried earliest); blanks
    // dropped; exact duplicates removed so a re-run cannot grow the list without
    // bound.
    expect(ekcRead(ekcEnvContents(), DataEncrypterFactory::PREVIOUS_ENV_KEY))
        ->toBe($old.','.$older);
});

it('preserves APP_KEY into the previous-key list on first adoption', function (): void {
    $appKey = ekcKey('app');

    ekcFixture("APP_NAME=Test\nAPP_KEY={$appKey}\n");

    $result = ekcRun();

    expect($result['status'])->toBe(0);

    $after = ekcEnvContents();

    expect(ekcRead($after, DataEncrypterFactory::PREVIOUS_ENV_KEY))->toBe($appKey)
        ->and($result['output'])->toContain('Previous key preserved from')
        ->and($result['output'])->toContain('APP_KEY');

    // Same ordering guarantee on the adoption path: before the first write the
    // file has no dedicated key at all, and the first flush must not introduce
    // one.
    expect($result['files']->writes)->toHaveCount(2)
        ->and(ekcRead($result['files']->writes[0], DataEncrypterFactory::PRIMARY_ENV_KEY))->toBeNull()
        ->and(ekcRead($result['files']->writes[0], DataEncrypterFactory::PREVIOUS_ENV_KEY))->toBe($appKey);
});

it('appends the encryption block exactly once when neither key is present in .env', function (): void {
    ekcFixture("APP_NAME=Test\nAPP_KEY=".ekcKey('app')."\n");

    expect(ekcRun()['status'])->toBe(0);

    $after = ekcEnvContents();

    expect(substr_count($after, '# ---- Encryption ----'))->toBe(1)
        ->and(ekcRead($after, DataEncrypterFactory::PRIMARY_ENV_KEY))->not->toBeNull()
        ->and(ekcRead($after, DataEncrypterFactory::PREVIOUS_ENV_KEY))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| 2. APP_KEY is never touched
|--------------------------------------------------------------------------
*/

it('leaves every APP_KEY line byte-identical across a rotation', function (): void {
    $appKey = ekcKey('app');

    $before = "APP_NAME=Test\nAPP_KEY={$appKey}\n# APP_KEY=base64:commented-out-old\nDATA_ENCRYPTION_KEY=".ekcKey('old')."\n";

    ekcFixture($before);

    expect(ekcRun()['status'])->toBe(0);

    $after = ekcEnvContents();

    expect(ekcAppKeyLines($after))->toBe(ekcAppKeyLines($before))
        ->and(ekcAppKeyLines($after))->toBe(["APP_KEY={$appKey}", '# APP_KEY=base64:commented-out-old'])
        ->and($after)->toContain("APP_KEY={$appKey}\n");
});

/*
|--------------------------------------------------------------------------
| 3. --show writes nothing
|--------------------------------------------------------------------------
*/

it('--show prints a usable key and writes nothing at all', function (): void {
    $before = "APP_NAME=Test\nAPP_KEY=".ekcKey('app')."\nDATA_ENCRYPTION_KEY=".ekcKey('old')."\n";

    ekcFixture($before);

    $result = ekcRun(['--show' => true]);

    expect($result['status'])->toBe(0)
        // No write of any kind reached the filesystem.
        ->and($result['files']->writes)->toBe([])
        ->and(ekcEnvContents())->toBe($before);

    $printed = trim($result['output']);

    expect($printed)->toStartWith('base64:')
        ->and(strlen((string) base64_decode(substr($printed, 7), true)))->toBe(32);
});

it('--show works in a production-looking environment because it mutates nothing', function (): void {
    $before = 'APP_KEY='.ekcKey('app')."\n";

    ekcFixture($before);

    app()->instance('env', 'production');

    $result = ekcRun(['--show' => true]);

    expect($result['status'])->toBe(0)
        ->and($result['files']->writes)->toBe([])
        ->and(ekcEnvContents())->toBe($before);
});

/*
|--------------------------------------------------------------------------
| 4. Production refuses without --force
|--------------------------------------------------------------------------
*/

it('refuses to rotate in a production-looking environment without --force', function (string $environment): void {
    $before = 'APP_KEY='.ekcKey('app')."\nDATA_ENCRYPTION_KEY=".ekcKey('old')."\n";

    ekcFixture($before);

    app()->instance('env', $environment);

    $result = ekcRun();

    expect($result['status'])->toBe(1)
        ->and($result['files']->writes)->toBe([])
        ->and(ekcEnvContents())->toBe($before)
        ->and($result['output'])->toContain('looks like production');
})->with(['production', 'prod', 'prod-eu', 'my-prod']);

it('rotates in a production-looking environment once --force is given', function (): void {
    $old = ekcKey('old');

    ekcFixture('APP_KEY='.ekcKey('app')."\nDATA_ENCRYPTION_KEY={$old}\n");

    app()->instance('env', 'production');

    $result = ekcRun(['--force' => true]);

    expect($result['status'])->toBe(0)
        ->and($result['files']->writes)->toHaveCount(2)
        ->and(ekcRead(ekcEnvContents(), DataEncrypterFactory::PREVIOUS_ENV_KEY))->toBe($old);
});

/*
|--------------------------------------------------------------------------
| 5. Refusals that must not half-write
|--------------------------------------------------------------------------
*/

it('refuses when there is no .env to rotate, and creates none', function (): void {
    $result = ekcRun();

    expect($result['status'])->toBe(1)
        ->and($result['files']->writes)->toBe([])
        ->and(file_exists(ekcEnvPath()))->toBeFalse()
        ->and($result['output'])->toContain('sk:install');
});

it('refuses an unsupported cipher before generating or writing anything', function (): void {
    $before = 'APP_KEY='.ekcKey('app')."\n";

    ekcFixture($before);

    config(['starter-kit.encryption.cipher' => 'AES-999-XYZ']);

    $result = ekcRun();

    expect($result['status'])->toBe(1)
        ->and($result['files']->writes)->toBe([])
        ->and(ekcEnvContents())->toBe($before)
        ->and($result['output'])->toContain('AES-999-XYZ');
});

/*
|--------------------------------------------------------------------------
| 8. File identity — the .env must survive the write as itself
|--------------------------------------------------------------------------
|
| A temp-file-plus-rename write replaces the inode. On the one file this
| feature exists to protect that is not a detail: a shared-env deploy layout
| (Envoyer/Deployer/Capistrano) symlinks .env into each release, and replacing
| the link writes the new key into a directory the next deploy discards — the
| operator then rekeys every row onto a key that vanishes. The mode case is the
| quieter half: a hardened 0600 .env coming back 0644 widens who can read the
| key that just landed in it.
|
*/

it('writes through a symlinked .env instead of replacing the link', function (): void {
    $shared = $this->ekcBasePath.'/shared';
    mkdir($shared, 0755, true);

    $realEnv = $shared.'/.env';
    file_put_contents($realEnv, 'APP_KEY='.ekcKey('app')."\n");

    symlink($realEnv, ekcEnvPath());

    $result = ekcRun();

    expect($result['status'])->toBe(0)
        ->and(is_link(ekcEnvPath()))->toBeTrue()
        ->and(readlink(ekcEnvPath()))->toBe($realEnv)
        ->and(ekcRead((string) file_get_contents($realEnv), DataEncrypterFactory::PRIMARY_ENV_KEY))->not->toBeNull();

    @unlink(ekcEnvPath());
    @unlink($realEnv);
    @rmdir($shared);
});

it('preserves the .env permission bits across a rotation', function (): void {
    ekcFixture('APP_KEY='.ekcKey('app')."\n");
    chmod(ekcEnvPath(), 0600);

    $result = ekcRun();

    expect($result['status'])->toBe(0)
        ->and(fileperms(ekcEnvPath()) & 0777)->toBe(0600);
});
