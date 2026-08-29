<?php

/*
|--------------------------------------------------------------------------
| encryption:rekey — the write side of the key rotation
|--------------------------------------------------------------------------
|
| The properties this file exists to keep true, each one a data-loss edge:
|
|   1. ONLY STALE ROWS ARE REWRITTEN. A value already on the primary key is not
|      re-encrypted, so a re-run is free and a mixed table converges.
|   2. AN UNDECRYPTABLE ROW IS LEFT BYTE-FOR-BYTE UNTOUCHED. Not nulled, not
|      deleted, not overwritten with a re-encryption of a failed decrypt. It is
|      counted, listed by identifier, and it makes the run exit 1 — the operator
|      must be stopped from clearing DATA_ENCRYPTION_PREVIOUS_KEYS.
|   3. --dry-run WRITES NOTHING and reports the SAME counts a real run would,
|      so it can gate a deploy.
|   4. --only SCOPES the run. An unrecognised surface must not silently widen
|      into "all", which would rewrite data deliberately scoped out.
|   5. A MISSING COLUMN SKIPS the surface rather than erroring: the kit ships to
|      apps that never published the settings migration and to apps where
|      Fortify's 2FA columns were never added.
|
| Assertions are on round-trip PLAINTEXT and on the stored bytes, never on
| expected ciphertext: the IV is random, so identical plaintext encrypts
| differently every call. "Untouched" is therefore asserted as byte equality
| against the value read before the run, and "rewritten" as byte inequality plus
| a decrypt with the PRIMARY key alone.
|
| Tables are built inline (this directory is bound to the non-DB TestCase in
| tests/Pest.php, and a file may only take one base class), and torn down after
| each test.
|
| Helpers carry an `erk` prefix — Pest helpers are global for the whole process.
|
*/

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lvntr\StarterKit\Support\Encryption\DataEncrypterFactory;

/**
 * Deterministic 32-byte key material for a seed.
 */
function erkBytes(string $seed): string
{
    return substr(hash('sha256', $seed, true), 0, 32);
}

function erkKey(string $seed): string
{
    return 'base64:'.base64_encode(erkBytes($seed));
}

/**
 * A single-key encrypter, used to WRITE fixture ciphertext under a chosen key
 * and to prove afterwards which key can read a stored value.
 */
function erkEncrypter(string $seed): Encrypter
{
    return new Encrypter(erkBytes($seed), 'AES-256-CBC');
}

/**
 * Configure the key chain: primary + previous list, with APP_KEY last.
 *
 * @param  list<string>  $previousSeeds
 */
function erkConfigureKeys(string $primarySeed, array $previousSeeds = [], string $appKeySeed = 'app'): void
{
    config([
        'app.key' => erkKey($appKeySeed),
        'app.cipher' => 'AES-256-CBC',
        'app.previous_keys' => [],
        'starter-kit.encryption.key' => erkKey($primarySeed),
        'starter-kit.encryption.previous_keys' => implode(',', array_map('erkKey', $previousSeeds)),
        'starter-kit.encryption.cipher' => null,
    ]);

    // The command flushes the memoised chain itself; this keeps any factory
    // resolved earlier in the same test from disagreeing.
    app(DataEncrypterFactory::class)->flush();
}

function erkCreateSettingsTable(bool $withEncryptedColumn = true): void
{
    Schema::create('settings', function (Blueprint $table) use ($withEncryptedColumn): void {
        $table->increments('id');
        $table->string('group')->nullable();
        $table->string('key');
        $table->text('value')->nullable();

        if ($withEncryptedColumn) {
            $table->boolean('encrypted')->default(false);
        }
    });
}

function erkCreateUsersTable(bool $withTwoFactorColumns = true): void
{
    Schema::create('users', function (Blueprint $table) use ($withTwoFactorColumns): void {
        $table->increments('id');
        $table->string('email')->nullable();

        if ($withTwoFactorColumns) {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
        }
    });
}

/**
 * @return int the inserted row id
 */
function erkInsertSetting(string $group, string $key, ?string $value, bool $encrypted = true): int
{
    return (int) DB::table('settings')->insertGetId([
        'group' => $group,
        'key' => $key,
        'value' => $value,
        'encrypted' => $encrypted ? 1 : 0,
    ]);
}

function erkSettingValue(int $id): ?string
{
    $value = DB::table('settings')->where('id', $id)->value('value');

    return is_string($value) ? $value : null;
}

function erkRekey(array $parameters = []): array
{
    $status = Artisan::call('encryption:rekey', $parameters);

    return ['status' => $status, 'output' => Artisan::output()];
}

/**
 * The per-surface summary line, with the dry-run wording normalised away.
 *
 * This is what "identical counts" is asserted on: a dry run and the real run it
 * predicts must produce the same numbers, and only the tense may differ.
 */
function erkSummaryLine(string $output, string $surface): string
{
    foreach (preg_split('/\R/', $output) ?: [] as $line) {
        if (str_starts_with(trim($line), $surface.' ')) {
            return trim(str_replace('would be rekeyed', 'rekeyed', $line));
        }
    }

    return '';
}

beforeEach(function (): void {
    Schema::dropIfExists('settings');
    Schema::dropIfExists('users');
});

afterEach(function (): void {
    Schema::dropIfExists('settings');
    Schema::dropIfExists('users');
});

/*
|--------------------------------------------------------------------------
| 1. Only stale rows move
|--------------------------------------------------------------------------
*/

it('rewrites only the rows still on an old key and leaves primary-key rows byte-identical', function (): void {
    erkCreateSettingsTable();
    erkCreateUsersTable();

    erkConfigureKeys(primarySeed: 'primary', previousSeeds: ['retired']);

    $retired = erkEncrypter('retired');
    $primary = erkEncrypter('primary');

    $staleA = erkInsertSetting('mail', 'password', $retired->encryptString('mail-secret'));
    $staleB = erkInsertSetting('storage', 'secret', $retired->encryptString('storage-secret'));
    $current = erkInsertSetting('turnstile', 'secret', $primary->encryptString('turnstile-secret'));

    // Plaintext rows are not in the encrypted set at all and must not be read.
    $plain = erkInsertSetting('app', 'name', 'Acme', encrypted: false);

    $currentBefore = erkSettingValue($current);
    $plainBefore = erkSettingValue($plain);

    $result = erkRekey();

    expect($result['status'])->toBe(0);

    // The two stale rows changed on disk and now read with the PRIMARY key alone.
    foreach ([$staleA => 'mail-secret', $staleB => 'storage-secret'] as $id => $expected) {
        $stored = erkSettingValue($id);

        expect($stored)->not->toBeNull()
            ->and($primary->decryptString((string) $stored))->toBe($expected);
    }

    // The row already on the primary key was NOT churned.
    expect(erkSettingValue($current))->toBe($currentBefore);

    // The unencrypted row was never in scope.
    expect(erkSettingValue($plain))->toBe($plainBefore);

    expect($result['output'])->toContain('3 row(s) scanned')
        ->toContain('1 value(s) already on the primary key')
        ->toContain('2 rekeyed')
        ->toContain(DataEncrypterFactory::PREVIOUS_ENV_KEY.'[0]: 2')
        ->toContain('Every scanned value now decrypts with the primary key alone.');
});

it('is idempotent: a second run finds every value already on the primary key', function (): void {
    erkCreateSettingsTable();
    erkCreateUsersTable();

    erkConfigureKeys(primarySeed: 'primary', previousSeeds: ['retired']);

    $id = erkInsertSetting('mail', 'password', erkEncrypter('retired')->encryptString('mail-secret'));

    expect(erkRekey()['status'])->toBe(0);

    $afterFirst = erkSettingValue($id);

    $second = erkRekey();

    expect($second['status'])->toBe(0)
        ->and(erkSettingValue($id))->toBe($afterFirst)
        ->and($second['output'])->toContain('1 value(s) already on the primary key')
        ->and($second['output'])->toContain('0 rekeyed');
});

it('moves 2FA secrets and recovery codes onto the primary key', function (): void {
    erkCreateSettingsTable();
    erkCreateUsersTable();

    erkConfigureKeys(primarySeed: 'primary', previousSeeds: ['retired']);

    $retired = erkEncrypter('retired');

    DB::table('users')->insert([
        'id' => 1,
        'email' => 'a@example.test',
        'two_factor_secret' => $retired->encryptString('OTPSECRET'),
        'two_factor_recovery_codes' => $retired->encryptString('["code-1","code-2"]'),
    ]);

    // A user with neither column set is expected, not a finding: the surface
    // filter is an OR over the two columns.
    DB::table('users')->insert(['id' => 2, 'email' => 'b@example.test']);

    $result = erkRekey(['--only' => 'two-factor']);

    expect($result['status'])->toBe(0);

    $row = DB::table('users')->where('id', 1)->first();
    $primary = erkEncrypter('primary');

    expect($primary->decryptString((string) $row->two_factor_secret))->toBe('OTPSECRET')
        ->and($primary->decryptString((string) $row->two_factor_recovery_codes))->toBe('["code-1","code-2"]')
        ->and($result['output'])->toContain('1 row(s) scanned')
        ->and($result['output'])->toContain('2 rekeyed');
});

/*
|--------------------------------------------------------------------------
| 2. An undecryptable row is untouched, reported, and exits 1
|--------------------------------------------------------------------------
*/

it('leaves a row no configured key can read byte-for-byte identical, reports it, and exits 1', function (): void {
    erkCreateSettingsTable();
    erkCreateUsersTable();

    erkConfigureKeys(primarySeed: 'primary', previousSeeds: ['retired']);

    $movable = erkInsertSetting('mail', 'password', erkEncrypter('retired')->encryptString('mail-secret'));

    // Written with a key that is NOT in the chain — the exact shape of "the
    // operator dropped a key from .env".
    $orphan = erkInsertSetting('storage', 'secret', erkEncrypter('lost-forever')->encryptString('storage-secret'));

    $orphanBefore = erkSettingValue($orphan);

    $result = erkRekey();

    expect($result['status'])->toBe(1);

    // NOTHING was written over the orphan.
    expect(erkSettingValue($orphan))->toBe($orphanBefore)
        ->and($orphanBefore)->not->toBeNull();

    // The readable row still moved — one bad row does not abort the run.
    expect(erkEncrypter('primary')->decryptString((string) erkSettingValue($movable)))->toBe('mail-secret');

    expect($result['output'])->toContain('1 unreadable')
        // Reported by IDENTIFIER, never by value.
        ->toContain('storage.secret')
        ->toContain('left BYTE-FOR-BYTE untouched')
        ->toContain('Do NOT clear '.DataEncrypterFactory::PREVIOUS_ENV_KEY)
        // The ciphertext itself must not be echoed.
        ->not->toContain((string) $orphanBefore);
});

it('treats a present-but-unusable value as unreadable and never overwrites it', function (): void {
    erkCreateSettingsTable();
    erkCreateUsersTable();

    erkConfigureKeys(primarySeed: 'primary');

    // Flagged encrypted but holding an empty string: no key can read it, and
    // nothing may be written over it.
    $blank = erkInsertSetting('mail', 'password', '   ');

    $result = erkRekey(['--only' => 'settings']);

    expect($result['status'])->toBe(1)
        ->and(erkSettingValue($blank))->toBe('   ')
        ->and($result['output'])->toContain('1 unreadable')
        ->and($result['output'])->toContain('mail.password');
});

/*
|--------------------------------------------------------------------------
| 3. --dry-run
|--------------------------------------------------------------------------
*/

it('--dry-run writes nothing and reports the same counts the real run produces', function (): void {
    erkCreateSettingsTable();
    erkCreateUsersTable();

    erkConfigureKeys(primarySeed: 'primary', previousSeeds: ['retired']);

    $retired = erkEncrypter('retired');
    $primary = erkEncrypter('primary');

    $staleA = erkInsertSetting('mail', 'password', $retired->encryptString('mail-secret'));
    $staleB = erkInsertSetting('storage', 'secret', $retired->encryptString('storage-secret'));
    $current = erkInsertSetting('turnstile', 'secret', $primary->encryptString('turnstile-secret'));

    $before = [
        $staleA => erkSettingValue($staleA),
        $staleB => erkSettingValue($staleB),
        $current => erkSettingValue($current),
    ];

    $dry = erkRekey(['--dry-run' => true]);

    expect($dry['status'])->toBe(0)
        ->and($dry['output'])->toContain('DRY RUN')
        ->and($dry['output'])->toContain('Dry run: nothing was written');

    // Not one byte moved.
    foreach ($before as $id => $value) {
        expect(erkSettingValue($id))->toBe($value);
    }

    $real = erkRekey();

    expect($real['status'])->toBe($dry['status'])
        ->and(erkSummaryLine($real['output'], 'settings'))
        ->toBe(erkSummaryLine($dry['output'], 'settings'))
        ->and(erkSummaryLine($real['output'], 'two-factor'))
        ->toBe(erkSummaryLine($dry['output'], 'two-factor'));

    // And the real run did move them.
    expect(erkSettingValue($staleA))->not->toBe($before[$staleA]);
});

it('--dry-run reports an unreadable row and exits 1, so it can gate a deploy', function (): void {
    erkCreateSettingsTable();
    erkCreateUsersTable();

    erkConfigureKeys(primarySeed: 'primary');

    $orphan = erkInsertSetting('storage', 'secret', erkEncrypter('lost-forever')->encryptString('x'));
    $before = erkSettingValue($orphan);

    $result = erkRekey(['--dry-run' => true]);

    expect($result['status'])->toBe(1)
        ->and(erkSettingValue($orphan))->toBe($before)
        ->and($result['output'])->toContain('1 unreadable');
});

/*
|--------------------------------------------------------------------------
| 4. --only and --chunk
|--------------------------------------------------------------------------
*/

it('--only scopes the run to the named surface and leaves the other one alone', function (): void {
    erkCreateSettingsTable();
    erkCreateUsersTable();

    erkConfigureKeys(primarySeed: 'primary', previousSeeds: ['retired']);

    $retired = erkEncrypter('retired');

    $setting = erkInsertSetting('mail', 'password', $retired->encryptString('mail-secret'));

    DB::table('users')->insert([
        'id' => 1,
        'two_factor_secret' => $retired->encryptString('OTPSECRET'),
    ]);

    $userBefore = DB::table('users')->where('id', 1)->value('two_factor_secret');

    $result = erkRekey(['--only' => 'settings']);

    expect($result['status'])->toBe(0)
        // The scoped-in surface moved.
        ->and(erkEncrypter('primary')->decryptString((string) erkSettingValue($setting)))->toBe('mail-secret')
        // The scoped-out surface was not read and not written.
        ->and(DB::table('users')->where('id', 1)->value('two_factor_secret'))->toBe($userBefore)
        ->and($result['output'])->toContain('settings')
        ->and($result['output'])->not->toContain('two-factor');
});

it('--only accepts the documented aliases and de-duplicates them', function (string $alias): void {
    erkCreateSettingsTable();
    erkCreateUsersTable();

    erkConfigureKeys(primarySeed: 'primary');

    $result = erkRekey(['--only' => $alias]);

    expect($result['status'])->toBe(0)
        ->and($result['output'])->toContain('two-factor');
})->with(['two-factor', 'two_factor', 'twofactor', '2fa']);

it('--only rejects an unknown surface instead of silently widening to all', function (): void {
    erkCreateSettingsTable();
    erkCreateUsersTable();

    erkConfigureKeys(primarySeed: 'primary', previousSeeds: ['retired']);

    $id = erkInsertSetting('mail', 'password', erkEncrypter('retired')->encryptString('mail-secret'));
    $before = erkSettingValue($id);

    $result = erkRekey(['--only' => 'everything']);

    expect($result['status'])->toBe(2)
        ->and(erkSettingValue($id))->toBe($before)
        ->and($result['output'])->toContain('Unknown surface [everything]');
});

it('--chunk rejects a non-numeric value before touching the database', function (): void {
    erkCreateSettingsTable();
    erkCreateUsersTable();

    erkConfigureKeys(primarySeed: 'primary', previousSeeds: ['retired']);

    $id = erkInsertSetting('mail', 'password', erkEncrypter('retired')->encryptString('mail-secret'));
    $before = erkSettingValue($id);

    $result = erkRekey(['--chunk' => 'abc']);

    expect($result['status'])->toBe(2)
        ->and(erkSettingValue($id))->toBe($before)
        ->and($result['output'])->toContain('--chunk must be a positive integer');
});

it('pages correctly across a chunk boundary', function (): void {
    erkCreateSettingsTable();
    erkCreateUsersTable();

    erkConfigureKeys(primarySeed: 'primary', previousSeeds: ['retired']);

    $retired = erkEncrypter('retired');
    $ids = [];

    for ($i = 0; $i < 7; $i++) {
        $ids[] = erkInsertSetting('group'.$i, 'key'.$i, $retired->encryptString('value-'.$i));
    }

    $result = erkRekey(['--only' => 'settings', '--chunk' => '2']);

    expect($result['status'])->toBe(0)
        ->and($result['output'])->toContain('7 row(s) scanned')
        ->and($result['output'])->toContain('7 rekeyed');

    $primary = erkEncrypter('primary');

    foreach ($ids as $i => $id) {
        expect($primary->decryptString((string) erkSettingValue($id)))->toBe('value-'.$i);
    }
});

/*
|--------------------------------------------------------------------------
| 5. Missing tables and columns skip, they do not error
|--------------------------------------------------------------------------
*/

it('skips the two-factor surface when the 2FA columns are absent, without erroring', function (): void {
    erkCreateSettingsTable();
    erkCreateUsersTable(withTwoFactorColumns: false);

    erkConfigureKeys(primarySeed: 'primary', previousSeeds: ['retired']);

    $id = erkInsertSetting('mail', 'password', erkEncrypter('retired')->encryptString('mail-secret'));

    $result = erkRekey();

    expect($result['status'])->toBe(0)
        ->and($result['output'])->toContain('two-factor  skipped')
        ->and($result['output'])->toContain('has no [two_factor_secret, two_factor_recovery_codes] column')
        // The other surface still ran.
        ->and(erkEncrypter('primary')->decryptString((string) erkSettingValue($id)))->toBe('mail-secret');
});

it('rekeys the 2FA secret when only the recovery-codes column is absent', function (): void {
    erkCreateSettingsTable();

    Schema::create('users', function (Blueprint $table): void {
        $table->increments('id');
        $table->text('two_factor_secret')->nullable();
    });

    erkConfigureKeys(primarySeed: 'primary', previousSeeds: ['retired']);

    DB::table('users')->insert([
        'id' => 1,
        'two_factor_secret' => erkEncrypter('retired')->encryptString('OTPSECRET'),
    ]);

    $result = erkRekey(['--only' => '2fa']);

    expect($result['status'])->toBe(0)
        ->and(erkEncrypter('primary')->decryptString(
            (string) DB::table('users')->where('id', 1)->value('two_factor_secret')
        ))->toBe('OTPSECRET');
});

it('skips the settings surface when the encrypted flag column is absent', function (): void {
    erkCreateSettingsTable(withEncryptedColumn: false);
    erkCreateUsersTable();

    erkConfigureKeys(primarySeed: 'primary');

    DB::table('settings')->insert(['group' => 'app', 'key' => 'name', 'value' => 'Acme']);

    $result = erkRekey();

    expect($result['status'])->toBe(0)
        ->and($result['output'])->toContain('settings    skipped')
        ->and($result['output'])->toContain('has no [encrypted] column')
        // Nothing was read, so the plaintext row was never fed to a decrypt loop.
        ->and(DB::table('settings')->where('key', 'name')->value('value'))->toBe('Acme');
});

it('skips a surface whose table does not exist at all', function (): void {
    erkCreateUsersTable();

    erkConfigureKeys(primarySeed: 'primary');

    $result = erkRekey();

    expect($result['status'])->toBe(0)
        ->and($result['output'])->toContain('settings    skipped')
        ->and($result['output'])->toContain('is not present on this install');
});

/*
|--------------------------------------------------------------------------
| 6. A chain that does not resolve reads and writes nothing
|--------------------------------------------------------------------------
*/

it('fails closed before reading a single row when the key chain does not resolve', function (): void {
    erkCreateSettingsTable();
    erkCreateUsersTable();

    config([
        'app.key' => erkKey('app'),
        'app.cipher' => 'AES-256-CBC',
        'app.previous_keys' => [],
        'starter-kit.encryption.key' => 'base64:not-valid-base64!!',
        'starter-kit.encryption.previous_keys' => null,
        'starter-kit.encryption.cipher' => null,
    ]);
    app(DataEncrypterFactory::class)->flush();

    $id = erkInsertSetting('mail', 'password', erkEncrypter('retired')->encryptString('mail-secret'));
    $before = erkSettingValue($id);

    $result = erkRekey();

    expect($result['status'])->toBe(1)
        ->and(erkSettingValue($id))->toBe($before)
        ->and($result['output'])->toContain('Nothing was read and nothing was written')
        ->and($result['output'])->toContain(DataEncrypterFactory::PRIMARY_ENV_KEY);
});

it('warns when the primary key is APP_KEY, because rekeying onto it re-couples the data', function (): void {
    erkCreateSettingsTable();
    erkCreateUsersTable();

    config([
        'app.key' => erkKey('app'),
        'app.cipher' => 'AES-256-CBC',
        'app.previous_keys' => [],
        'starter-kit.encryption.key' => null,
        'starter-kit.encryption.previous_keys' => erkKey('retired'),
        'starter-kit.encryption.cipher' => null,
    ]);
    app(DataEncrypterFactory::class)->flush();

    $result = erkRekey();

    expect($result['status'])->toBe(0)
        ->and($result['output'])->toContain('No '.DataEncrypterFactory::PRIMARY_ENV_KEY.' is configured')
        ->and($result['output'])->toContain('key:generate');
});
