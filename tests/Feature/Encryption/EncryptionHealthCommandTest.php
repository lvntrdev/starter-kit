<?php

/*
|--------------------------------------------------------------------------
| encryption:health — the gate in front of clearing the previous-key list
|--------------------------------------------------------------------------
|
| This command answers exactly one question: can DATA_ENCRYPTION_PREVIOUS_KEYS
| be cleared without losing data? A false "yes" is the single output in this
| whole feature that destroys data, so what is locked here is that "safe" is
| UNREACHABLE from every state that is not provably clean:
|
|   safe-to-clear   exit 0 — every surface scanned, every value on the primary key
|   rekey-required  exit 1 — at least one value still needs a non-primary key
|   incomplete      exit 1 — a surface could not be scanned, so nothing is vouched for
|   unreadable      exit 2 — a value no configured key can read exists
|   key-error       exit 2 — the chain itself does not resolve; nothing is scanned
|
| Precedence is asserted directly (unreadable outranks previous outranks
| incomplete), because a summary that merely counted would let an unreadable row
| hide behind a clean surface.
|
| The command is READ-ONLY. Row bytes are captured before and compared after,
| in the verdicts most likely to tempt a "cleanup".
|
| Helpers carry an `ehc` prefix — Pest helpers are global for the whole process.
|
*/

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lvntr\StarterKit\Console\Commands\EncryptionHealthCommand;
use Lvntr\StarterKit\Support\Encryption\DataEncrypterFactory;
use Lvntr\StarterKit\Support\Encryption\EncrypterCoverage;

function ehcBytes(string $seed): string
{
    return substr(hash('sha256', $seed, true), 0, 32);
}

function ehcKey(string $seed): string
{
    return 'base64:'.base64_encode(ehcBytes($seed));
}

function ehcEncrypter(string $seed): Encrypter
{
    return new Encrypter(ehcBytes($seed), 'AES-256-CBC');
}

/**
 * @param  list<string>  $previousSeeds
 */
function ehcConfigureKeys(?string $primarySeed, array $previousSeeds = [], ?string $appKeySeed = 'app'): void
{
    config([
        'app.key' => $appKeySeed === null ? null : ehcKey($appKeySeed),
        'app.cipher' => 'AES-256-CBC',
        'app.previous_keys' => [],
        'starter-kit.encryption.key' => $primarySeed === null ? null : ehcKey($primarySeed),
        'starter-kit.encryption.previous_keys' => $previousSeeds === []
            ? null
            : implode(',', array_map('ehcKey', $previousSeeds)),
        'starter-kit.encryption.cipher' => null,
    ]);

    app(DataEncrypterFactory::class)->flush();
}

function ehcCreateSettingsTable(bool $withEncryptedColumn = true): void
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

function ehcCreateUsersTable(bool $withTwoFactorColumns = true): void
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

function ehcInsertSetting(string $group, string $key, ?string $value, bool $encrypted = true): int
{
    return (int) DB::table('settings')->insertGetId([
        'group' => $group,
        'key' => $key,
        'value' => $value,
        'encrypted' => $encrypted ? 1 : 0,
    ]);
}

/**
 * @return array{status: int, output: string}
 */
function ehcHealth(array $parameters = []): array
{
    $status = Artisan::call('encryption:health', $parameters);

    return ['status' => $status, 'output' => Artisan::output()];
}

/**
 * @return array<string, mixed>
 */
function ehcJson(): array
{
    $result = ehcHealth(['--json' => true]);

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($result['output'], true, flags: JSON_THROW_ON_ERROR);

    // The exit code is part of the payload AND the process result; they must
    // agree, or a CI script reading one gets a different answer than a shell
    // reading the other.
    expect($decoded['exit_code'])->toBe($result['status']);

    return $decoded;
}

/**
 * Every settings.value byte currently on disk, keyed by id.
 *
 * @return array<int, string|null>
 */
function ehcSettingsSnapshot(): array
{
    $snapshot = [];

    foreach (DB::table('settings')->get() as $row) {
        $snapshot[(int) $row->id] = is_string($row->value) ? $row->value : null;
    }

    return $snapshot;
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
| 1. The verdicts and their exit codes
|--------------------------------------------------------------------------
*/

it('reports safe-to-clear with exit 0 when every scanned value is on the primary key', function (): void {
    ehcCreateSettingsTable();
    ehcCreateUsersTable();

    ehcConfigureKeys(primarySeed: 'primary', previousSeeds: ['retired']);

    $primary = ehcEncrypter('primary');

    ehcInsertSetting('mail', 'password', $primary->encryptString('mail-secret'));
    ehcInsertSetting('storage', 'secret', $primary->encryptString('storage-secret'));

    DB::table('users')->insert([
        'id' => 1,
        'two_factor_secret' => $primary->encryptString('OTPSECRET'),
    ]);

    $before = ehcSettingsSnapshot();

    $result = ehcHealth();

    expect($result['status'])->toBe(0)
        ->and($result['output'])->toContain('Safe to clear '.DataEncrypterFactory::PREVIOUS_ENV_KEY)
        ->and($result['output'])->toContain('2 row(s) scanned — 2 on the primary key, 0 on a previous key, 0 unreadable')
        // Read-only: not one byte moved.
        ->and(ehcSettingsSnapshot())->toBe($before);
});

it('reports rekey-required with exit 1 when a value still rides a previous key', function (): void {
    ehcCreateSettingsTable();
    ehcCreateUsersTable();

    ehcConfigureKeys(primarySeed: 'primary', previousSeeds: ['retired']);

    ehcInsertSetting('mail', 'password', ehcEncrypter('primary')->encryptString('mail-secret'));
    ehcInsertSetting('storage', 'secret', ehcEncrypter('retired')->encryptString('storage-secret'));

    $before = ehcSettingsSnapshot();

    $result = ehcHealth();

    expect($result['status'])->toBe(1)
        ->and($result['output'])->toContain('1 value(s) still decrypt only with a non-primary key')
        ->and($result['output'])->toContain('encryption:rekey')
        // Attributed to the key by its SOURCE LABEL, never by its material.
        ->and($result['output'])->toContain(DataEncrypterFactory::PREVIOUS_ENV_KEY.'[0]: 1')
        ->and(ehcSettingsSnapshot())->toBe($before);
});

it('reports unreadable with exit 2 when no configured key can read a value', function (): void {
    ehcCreateSettingsTable();
    ehcCreateUsersTable();

    ehcConfigureKeys(primarySeed: 'primary');

    ehcInsertSetting('mail', 'password', ehcEncrypter('primary')->encryptString('mail-secret'));
    ehcInsertSetting('storage', 'secret', ehcEncrypter('lost-forever')->encryptString('storage-secret'));

    $before = ehcSettingsSnapshot();

    $result = ehcHealth();

    expect($result['status'])->toBe(2)
        ->and($result['output'])->toContain('cannot be read by ANY configured key')
        ->and($result['output'])->toContain('ADD it to '.DataEncrypterFactory::PREVIOUS_ENV_KEY)
        ->and($result['output'])->toContain('unreadable: storage.secret')
        // Nothing is "cleaned up" — the ciphertext stays intact on disk.
        ->and(ehcSettingsSnapshot())->toBe($before);
});

it('reports key-error with exit 2 and scans nothing when the chain does not resolve', function (): void {
    ehcCreateSettingsTable();
    ehcCreateUsersTable();

    config([
        'app.key' => ehcKey('app'),
        'app.cipher' => 'AES-256-CBC',
        'app.previous_keys' => [],
        'starter-kit.encryption.key' => 'base64:not-valid-base64!!',
        'starter-kit.encryption.previous_keys' => null,
        'starter-kit.encryption.cipher' => null,
    ]);
    app(DataEncrypterFactory::class)->flush();

    ehcInsertSetting('mail', 'password', ehcEncrypter('primary')->encryptString('mail-secret'));

    $result = ehcHealth();

    expect($result['status'])->toBe(2)
        ->and($result['output'])->toContain('no value could be attributed to a key')
        ->and($result['output'])->toContain(DataEncrypterFactory::PRIMARY_ENV_KEY)
        ->and($result['output'])->toContain('Do NOT clear '.DataEncrypterFactory::PREVIOUS_ENV_KEY)
        // No surface line at all: nothing was scanned.
        ->and($result['output'])->not->toContain('row(s) scanned');
});

/*
|--------------------------------------------------------------------------
| 2. A partially scannable surface downgrades the verdict
|--------------------------------------------------------------------------
*/

it('downgrades an otherwise clean install to incomplete when a surface cannot be scanned', function (): void {
    ehcCreateSettingsTable();
    // No 2FA columns: the surface exists but cannot be vouched for.
    ehcCreateUsersTable(withTwoFactorColumns: false);

    ehcConfigureKeys(primarySeed: 'primary');

    ehcInsertSetting('mail', 'password', ehcEncrypter('primary')->encryptString('mail-secret'));

    $result = ehcHealth();

    // Everything READ was clean, and it is still not safe to clear.
    expect($result['status'])->toBe(1)
        ->and($result['output'])->toContain('two-factor  NOT SCANNED')
        ->and($result['output'])->toContain('1 surface(s) could not be fully scanned')
        ->and($result['output'])->toContain('Do NOT clear '.DataEncrypterFactory::PREVIOUS_ENV_KEY)
        ->and($result['output'])->not->toContain('Safe to clear');
});

it('reports a missing table as unscannable rather than as clean', function (): void {
    ehcCreateUsersTable();

    ehcConfigureKeys(primarySeed: 'primary');

    $result = ehcHealth();

    expect($result['status'])->toBe(1)
        ->and($result['output'])->toContain('settings    NOT SCANNED')
        ->and($result['output'])->toContain('is not present on this install')
        ->and($result['output'])->not->toContain('Safe to clear');
});

it('reports a settings table with no encrypted flag column as unscannable', function (): void {
    ehcCreateSettingsTable(withEncryptedColumn: false);
    ehcCreateUsersTable();

    ehcConfigureKeys(primarySeed: 'primary');

    DB::table('settings')->insert(['group' => 'app', 'key' => 'name', 'value' => 'Acme']);

    $result = ehcHealth();

    expect($result['status'])->toBe(1)
        ->and($result['output'])->toContain('settings    NOT SCANNED')
        ->and($result['output'])->toContain('has no [encrypted] column');
});

/*
|--------------------------------------------------------------------------
| 3. Verdict precedence — the loudest true statement wins
|--------------------------------------------------------------------------
*/

it('prefers unreadable over rekey-required and over incomplete', function (): void {
    ehcCreateSettingsTable();
    ehcCreateUsersTable(withTwoFactorColumns: false);

    ehcConfigureKeys(primarySeed: 'primary', previousSeeds: ['retired']);

    ehcInsertSetting('mail', 'password', ehcEncrypter('retired')->encryptString('mail-secret'));
    ehcInsertSetting('storage', 'secret', ehcEncrypter('lost-forever')->encryptString('storage-secret'));

    $decoded = ehcJson();

    expect($decoded['verdict'])->toBe(EncryptionHealthCommand::VERDICT_UNREADABLE)
        ->and($decoded['exit_code'])->toBe(2)
        ->and($decoded['summary']['previous'])->toBe(1)
        ->and($decoded['summary']['unreadable'])->toBe(1)
        ->and($decoded['summary']['incomplete'])->toBe(1);
});

it('prefers rekey-required over incomplete', function (): void {
    ehcCreateSettingsTable();
    ehcCreateUsersTable(withTwoFactorColumns: false);

    ehcConfigureKeys(primarySeed: 'primary', previousSeeds: ['retired']);

    ehcInsertSetting('mail', 'password', ehcEncrypter('retired')->encryptString('mail-secret'));

    $decoded = ehcJson();

    expect($decoded['verdict'])->toBe(EncryptionHealthCommand::VERDICT_REKEY_REQUIRED)
        ->and($decoded['exit_code'])->toBe(1)
        ->and($decoded['summary']['incomplete'])->toBe(1);
});

/*
|--------------------------------------------------------------------------
| 4. --json shape
|--------------------------------------------------------------------------
*/

it('--json emits the documented shape and never any key material', function (): void {
    ehcCreateSettingsTable();
    ehcCreateUsersTable();

    ehcConfigureKeys(primarySeed: 'primary', previousSeeds: ['retired']);

    ehcInsertSetting('mail', 'password', ehcEncrypter('retired')->encryptString('mail-secret'));

    $result = ehcHealth(['--json' => true]);

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($result['output'], true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($decoded))->toBe([
        'version', 'generated_at', 'verdict', 'safe_to_clear', 'exit_code',
        'message', 'keys', 'config_block', 'coverage', 'summary', 'surfaces',
    ]);

    expect($decoded['version'])->toBe(1)
        ->and($decoded['verdict'])->toBe(EncryptionHealthCommand::VERDICT_REKEY_REQUIRED)
        ->and($decoded['safe_to_clear'])->toBeFalse()
        ->and($decoded['exit_code'])->toBe(1)
        ->and($result['status'])->toBe(1);

    expect(array_keys($decoded['keys']))->toBe([
        'source', 'using_dedicated_key', 'cipher', 'chain', 'previous_keys_in_chain',
        'previous_keys_env_set', 'app_previous_keys_in_chain', 'app_key_in_chain',
        'app_key_chain_source',
    ]);

    expect($decoded['keys']['source'])->toBe(DataEncrypterFactory::PRIMARY_ENV_KEY)
        ->and($decoded['keys']['using_dedicated_key'])->toBeTrue()
        ->and($decoded['keys']['cipher'])->toBe('AES-256-CBC')
        ->and($decoded['keys']['chain'])->toBe([
            DataEncrypterFactory::PRIMARY_ENV_KEY,
            DataEncrypterFactory::PREVIOUS_ENV_KEY.'[0]',
            DataEncrypterFactory::APP_ENV_KEY,
        ])
        ->and($decoded['keys']['previous_keys_in_chain'])->toBe(1)
        ->and($decoded['keys']['previous_keys_env_set'])->toBeTrue()
        ->and($decoded['keys']['app_previous_keys_in_chain'])->toBe(0)
        ->and($decoded['keys']['app_key_in_chain'])->toBeTrue();

    expect(array_keys($decoded['summary']))
        ->toBe([
            'scanned', 'primary', 'previous', 'unreadable', 'incomplete', 'unvouched',
            'config_block_missing', 'env_chain_diverged',
        ]);

    // Coverage: WHO serves each surface, reported beside the row attribution.
    expect(array_keys($decoded['config_block']))
        ->toBe(['present', 'configuration_cached', 'primary_key_in_environment', 'env_chain_diverges'])
        ->and($decoded['config_block']['present'])->toBeTrue()
        // NULL, not false: the configuration is not cached in a test run, so
        // config() reads the live values and there is nothing to be stale
        // against. Reporting an unperformed check as a pass is the mistake this
        // tri-state exists to prevent.
        ->and($decoded['config_block']['env_chain_diverges'])->toBeNull()
        ->and($decoded['summary']['env_chain_diverged'])->toBeFalse();

    expect($decoded['coverage'])->toHaveCount(2)
        ->and(array_keys($decoded['coverage'][0]))
        ->toBe(['surface', 'status', 'encrypter', 'kit_built', 'detail'])
        ->and($decoded['coverage'][0]['surface'])->toBe('settings')
        ->and($decoded['coverage'][0]['status'])->toBe(EncrypterCoverage::STATUS_COVERED)
        ->and($decoded['coverage'][0]['kit_built'])->toBeTrue()
        ->and($decoded['summary']['unvouched'])->toBe(0)
        ->and($decoded['summary']['config_block_missing'])->toBeFalse();

    expect($decoded['surfaces'])->toHaveCount(2)
        ->and(array_keys($decoded['surfaces'][0]))->toBe([
            'name', 'table', 'status', 'detail', 'scanned', 'primary', 'previous',
            'unreadable', 'by_source', 'identifiers', 'overflow',
        ]);

    expect($decoded['surfaces'][0]['name'])->toBe('settings')
        ->and($decoded['surfaces'][0]['table'])->toBe('settings')
        ->and($decoded['surfaces'][0]['status'])->toBe('ok')
        ->and($decoded['surfaces'][0]['previous'])->toBe(1)
        ->and($decoded['surfaces'][0]['by_source'])
        ->toBe([DataEncrypterFactory::PREVIOUS_ENV_KEY.'[0]' => 1])
        ->and($decoded['surfaces'][1]['name'])->toBe('two-factor');

    // No key material anywhere in the payload — the chain is reported by env
    // var NAME only.
    expect($result['output'])->not->toContain(ehcKey('primary'))
        ->and($result['output'])->not->toContain(ehcKey('retired'))
        ->and($result['output'])->not->toContain(ehcKey('app'))
        ->and($result['output'])->not->toContain(base64_encode(ehcBytes('primary')));
});

it('--json reports an empty by_source as an object, not as a JSON array', function (): void {
    ehcCreateSettingsTable();
    ehcCreateUsersTable();

    ehcConfigureKeys(primarySeed: 'primary');

    ehcInsertSetting('mail', 'password', ehcEncrypter('primary')->encryptString('mail-secret'));

    $result = ehcHealth(['--json' => true]);

    // A consumer typed against an object map must not receive `[]` when nothing
    // rode an old key.
    expect($result['output'])->toContain('"by_source": {}')
        ->and($result['status'])->toBe(0);
});

it('--json reports the key-error verdict with a null keys block and no surfaces', function (): void {
    ehcCreateSettingsTable();
    ehcCreateUsersTable();

    config([
        'app.key' => null,
        'app.cipher' => 'AES-256-CBC',
        'app.previous_keys' => [],
        'starter-kit.encryption.key' => null,
        'starter-kit.encryption.previous_keys' => null,
        'starter-kit.encryption.cipher' => null,
    ]);
    app(DataEncrypterFactory::class)->flush();

    $decoded = ehcJson();

    expect($decoded['verdict'])->toBe(EncryptionHealthCommand::VERDICT_KEY_ERROR)
        ->and($decoded['exit_code'])->toBe(2)
        ->and($decoded['safe_to_clear'])->toBeFalse()
        ->and($decoded['keys'])->toBeNull()
        ->and($decoded['surfaces'])->toBe([])
        ->and($decoded['message'])->toContain(DataEncrypterFactory::PRIMARY_ENV_KEY)
        ->and($decoded['message'])->toContain(DataEncrypterFactory::APP_ENV_KEY);
});

/*
|--------------------------------------------------------------------------
| 5. The legacy population — no dedicated key at all
|--------------------------------------------------------------------------
*/

it('warns that APP_KEY is the primary key while still attributing rows to it', function (): void {
    ehcCreateSettingsTable();
    ehcCreateUsersTable();

    ehcConfigureKeys(primarySeed: null, appKeySeed: 'app');

    ehcInsertSetting('mail', 'password', ehcEncrypter('app')->encryptString('mail-secret'));

    $result = ehcHealth();

    expect($result['status'])->toBe(0)
        ->and($result['output'])->toContain('No '.DataEncrypterFactory::PRIMARY_ENV_KEY.' is configured')
        ->and($result['output'])->toContain('key:generate')
        ->and($result['output'])->toContain('1 on the primary key');
});

/*
|--------------------------------------------------------------------------
| 6. APP_KEY presence is decided on MATERIAL, not on a source label
|--------------------------------------------------------------------------
|
| This is the exact state `encryption:key` leaves behind on a first adoption:
| APP_KEY is retired into DATA_ENCRYPTION_PREVIOUS_KEYS[0], so the chain
| builder dedupes the invariant "APP_KEY last" entry away and no slot carries
| the APP_KEY label any more. Its bytes are still in the chain, and reporting
| otherwise would send the operator hunting for a key that is not missing.
|
*/

it('reports APP_KEY as in the chain when it was retired into the previous list', function (): void {
    ehcCreateSettingsTable();
    ehcCreateUsersTable();

    // Exactly what a first adoption produces: new dedicated primary, the old
    // APP_KEY sitting at DATA_ENCRYPTION_PREVIOUS_KEYS[0].
    ehcConfigureKeys(primarySeed: 'dedicated', previousSeeds: ['app'], appKeySeed: 'app');

    $result = ehcHealth();
    $decoded = ehcJson();

    expect($decoded['keys']['app_key_in_chain'])->toBeTrue()
        ->and($decoded['keys']['app_key_chain_source'])->toBe(DataEncrypterFactory::PREVIOUS_ENV_KEY.'[0]')
        ->and($result['output'])->toContain('in the read chain (as '.DataEncrypterFactory::PREVIOUS_ENV_KEY.'[0])')
        ->and($result['output'])->not->toContain('NOT in the read chain');
});

it('reports APP_KEY as NOT in the chain when its material is genuinely absent', function (): void {
    ehcCreateSettingsTable();
    ehcCreateUsersTable();

    ehcConfigureKeys(primarySeed: 'dedicated', appKeySeed: null);

    $decoded = ehcJson();

    expect($decoded['keys']['app_key_in_chain'])->toBeFalse()
        ->and($decoded['keys']['app_key_chain_source'])->toBeNull();
});
