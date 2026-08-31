<?php

/*
|--------------------------------------------------------------------------
| encryption:key — .env READ regression, pinned to phpdotenv semantics
|--------------------------------------------------------------------------
|
| EncryptionKeyCommand::readEnvValue() resolves the current primary key (and
| the previous-key list) through Dotenv::parse(), not a hand-rolled regex — see
| the docblock on readEnvValue() in src/Console/Commands/EncryptionKeyCommand.php.
| A regex regression would silently disagree with the running app about what a
| key's "effective value" is, which is exactly the class of bug that corrupts
| DATA_ENCRYPTION_PREVIOUS_KEYS. Each case below pins one promise the regex did
| NOT keep (or kept only by accident) so a reintroduced regex breaks a test
| instead of a production install.
|
| Reuses the `ekc*` helpers and EkcRecordingFilesystem declared in
| EncryptionKeyCommandTest.php — Pest functions are process-global, and the
| `ekc` prefix is the collision guard that file already documents.
|
*/

use Lvntr\StarterKit\Support\Encryption\DataEncrypterFactory;

require_once __DIR__.'/EncryptionKeyHarness.php';

beforeEach(function (): void {
    $this->ekcBasePath = sys_get_temp_dir().'/sk-encryption-key-envparse-'.bin2hex(random_bytes(6));
    mkdir($this->ekcBasePath, 0755, true);

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

it('resolves an interpolated DATA_ENCRYPTION_KEY (${APP_KEY}) to APP_KEY\'s value', function (): void {
    $appKey = ekcKey('app-interp');

    ekcFixture("APP_KEY={$appKey}\nDATA_ENCRYPTION_KEY=\${APP_KEY}\n");

    $result = ekcRun();

    expect($result['status'])->toBe(0);

    // The current primary resolved to $appKey (interpolated), so it is what
    // gets preserved as the retired key ahead of the freshly generated one.
    expect(ekcRead(ekcEnvContents(), DataEncrypterFactory::PREVIOUS_ENV_KEY))->toBe($appKey);
});

it('reads the LAST of several DATA_ENCRYPTION_KEY assignments in the same file', function (): void {
    $first = ekcKey('first-dupe');
    $last = ekcKey('last-dupe');

    ekcFixture(
        'APP_KEY='.ekcKey('app-dupe')."\n"
        ."DATA_ENCRYPTION_KEY={$first}\n"
        ."DATA_ENCRYPTION_KEY={$last}\n"
    );

    expect(ekcRun()['status'])->toBe(0);

    expect(ekcRead(ekcEnvContents(), DataEncrypterFactory::PREVIOUS_ENV_KEY))->toBe($last)
        ->and(ekcRead(ekcEnvContents(), DataEncrypterFactory::PREVIOUS_ENV_KEY))->not->toBe($first);
});

it('ignores a commented-out DATA_ENCRYPTION_KEY line and falls back to APP_KEY', function (): void {
    $appKey = ekcKey('app-commented');
    $commented = ekcKey('commented-out');

    ekcFixture(
        "APP_KEY={$appKey}\n"
        ."# DATA_ENCRYPTION_KEY={$commented}\n"
    );

    $result = ekcRun();

    expect($result['status'])->toBe(0)
        ->and(ekcRead(ekcEnvContents(), DataEncrypterFactory::PREVIOUS_ENV_KEY))->toBe($appKey)
        ->and($result['output'])->toContain('APP_KEY');
});

it('resolves an export-prefixed DATA_ENCRYPTION_KEY assignment', function (): void {
    $old = ekcKey('exported');

    ekcFixture(
        'APP_KEY='.ekcKey('app-export')."\n"
        ."export DATA_ENCRYPTION_KEY={$old}\n"
    );

    expect(ekcRun()['status'])->toBe(0);

    expect(ekcRead(ekcEnvContents(), DataEncrypterFactory::PREVIOUS_ENV_KEY))->toBe($old);
});

it('strips surrounding quotes from a quoted DATA_ENCRYPTION_KEY value', function (): void {
    $old = ekcKey('quoted');

    ekcFixture(
        'APP_KEY='.ekcKey('app-quoted')."\n"
        ."DATA_ENCRYPTION_KEY=\"{$old}\"\n"
    );

    expect(ekcRun()['status'])->toBe(0);

    expect(ekcRead(ekcEnvContents(), DataEncrypterFactory::PREVIOUS_ENV_KEY))->toBe($old);
});

it('treats a blank DATA_ENCRYPTION_KEY assignment as not set and falls back to APP_KEY', function (): void {
    $appKey = ekcKey('app-blank');

    ekcFixture(
        "APP_KEY={$appKey}\n"
        ."DATA_ENCRYPTION_KEY=\n"
    );

    $result = ekcRun();

    expect($result['status'])->toBe(0)
        ->and(ekcRead(ekcEnvContents(), DataEncrypterFactory::PREVIOUS_ENV_KEY))->toBe($appKey)
        ->and($result['output'])->toContain('APP_KEY');
});

it('aborts when the process environment overrides an interpolated key, writing nothing', function (): void {
    // The file says DATA_ENCRYPTION_KEY=${BASE_KEY} and defines BASE_KEY, but the
    // process carries a different BASE_KEY. Dotenv::parse() resolves the file
    // against an isolated repository, so it reports the file's value; the booted
    // app resolves ${BASE_KEY} against $_SERVER/$_ENV/getenv() and decrypts with
    // the OTHER one. Rotating on the parsed value would retire a key the app
    // never used and drop the one it did — unreadable ciphertext. Rewriting the
    // file could not fix it either, since the process value keeps winning; the
    // only safe move is to stop and let the operator resolve the divergence.
    $fromFile = ekcKey('base-in-file');
    $fromProcess = ekcKey('base-in-process');

    $before = 'APP_KEY='.ekcKey('app-override')."\n"
        ."BASE_KEY={$fromFile}\n"
        ."DATA_ENCRYPTION_KEY=\${BASE_KEY}\n";

    ekcFixture($before);

    $previousServer = $_SERVER['BASE_KEY'] ?? null;
    $_SERVER['BASE_KEY'] = $fromProcess;

    try {
        $result = ekcRun();
    } finally {
        if ($previousServer === null) {
            unset($_SERVER['BASE_KEY']);
        } else {
            $_SERVER['BASE_KEY'] = $previousServer;
        }
    }

    expect($result['status'])->toBe(1)
        ->and($result['files']->writes)->toBe([])
        ->and(ekcEnvContents())->toBe($before)
        ->and($result['output'])->toContain(DataEncrypterFactory::PRIMARY_ENV_KEY);

    // The abort message must name the problem, never the material.
    expect($result['output'])->not->toContain($fromFile)
        ->and($result['output'])->not->toContain($fromProcess);

    // Negative control: the divergence is what stopped the run, not the shape of
    // the fixture. The same file, with the process value gone, rotates normally
    // and retires exactly the interpolated value.
    $control = ekcRun();

    expect($control['status'])->toBe(0)
        ->and(ekcRead(ekcEnvContents(), DataEncrypterFactory::PREVIOUS_ENV_KEY))->toBe($fromFile);
});

it('aborts a malformed .env without writing anything, leaving the file byte-identical', function (): void {
    // Trailing content after a closed quoted value ("foo" bar) is invalid for
    // phpdotenv (InvalidFileException) and was never reliably rejected by a
    // hand-rolled regex — that gap is exactly what this case pins shut.
    $before = 'APP_KEY='.ekcKey('app-malformed')."\nDATA_ENCRYPTION_KEY=\"unterminated\" trailing\n";

    ekcFixture($before);

    $result = ekcRun();

    expect($result['status'])->toBe(1)
        ->and($result['files']->writes)->toBe([])
        ->and(ekcEnvContents())->toBe($before)
        ->and($result['output'])->not->toContain('unterminated');
});
