<?php

/*
|--------------------------------------------------------------------------
| Encrypter coverage — WHO serves each surface, not just which key opened it
|--------------------------------------------------------------------------
|
| encryption:health attributes stored ciphertext to a key. That is a statement
| about bytes already on disk and says nothing about the encrypter the app will
| use to read and write them tomorrow — and the two diverge silently:
|
|   - Fortify's 2FA columns go through Fortify::$encrypter ?? Model::$encrypter
|     ?? Crypt. The kit installs its shim ONLY when both statics are null,
|     because overwriting a consumer's encrypter would lock every 2FA user out.
|     A consumer that set one keeps a foreign encrypter, and a rekey onto
|     DATA_ENCRYPTION_KEY would make its 2FA columns unreadable at the login
|     challenge — the exact loss the feature exists to prevent.
|   - A published config/starter-kit.php that predates the encryption release,
|     combined with config:cache, hides the whole `encryption` block. Every key
|     under it reads null, DATA_ENCRYPTION_KEY is inert, the primary silently
|     falls back to APP_KEY — and health, scanning rows that all read with
|     APP_KEY, used to report "safe to clear" on that install.
|
| What is locked here: neither state can produce a clean verdict, and the rekey
| REFUSES rather than re-encrypting a surface it cannot vouch for. Helpers carry
| an `ecv` prefix — Pest helpers are global for the whole process.
|
*/

use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Fortify;
use Lvntr\StarterKit\Console\Commands\EncryptionHealthCommand;
use Lvntr\StarterKit\Support\Encryption\DataEncrypterFactory;
use Lvntr\StarterKit\Support\Encryption\EncrypterCoverage;

function ecvBytes(string $seed): string
{
    return substr(hash('sha256', $seed, true), 0, 32);
}

function ecvKey(string $seed): string
{
    return 'base64:'.base64_encode(ecvBytes($seed));
}

function ecvEncrypter(string $seed): Encrypter
{
    return new Encrypter(ecvBytes($seed), 'AES-256-CBC');
}

function ecvConfigureKeys(?string $primarySeed = 'primary'): void
{
    config([
        'app.key' => ecvKey('app'),
        'app.cipher' => 'AES-256-CBC',
        'app.previous_keys' => [],
        'starter-kit.encryption' => [
            'key' => $primarySeed === null ? null : ecvKey($primarySeed),
            'previous_keys' => null,
            'cipher' => null,
        ],
    ]);

    app(DataEncrypterFactory::class)->flush();
}

function ecvCreateTables(): void
{
    Schema::create('settings', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('group')->nullable();
        $table->string('key');
        $table->text('value')->nullable();
        $table->boolean('encrypted')->default(false);
    });

    Schema::create('users', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('email')->nullable();
        $table->text('two_factor_secret')->nullable();
        $table->text('two_factor_recovery_codes')->nullable();
    });
}

/**
 * @return array<string, mixed>
 */
function ecvHealthJson(): array
{
    $status = Artisan::call('encryption:health', ['--json' => true]);

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['exit_code'])->toBe($status);

    return $decoded;
}

/**
 * @return array{status: int, output: string}
 */
function ecvRekey(array $parameters = []): array
{
    $status = Artisan::call('encryption:rekey', $parameters);

    return ['status' => $status, 'output' => Artisan::output()];
}

/**
 * Coverage entry for one surface out of a health payload.
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function ecvCoverage(array $payload, string $surface): array
{
    foreach ($payload['coverage'] as $entry) {
        if ($entry['surface'] === $surface) {
            return $entry;
        }
    }

    throw new RuntimeException("No coverage entry for surface [{$surface}].");
}

/**
 * Every variable the environment-divergence tests plant in the real process
 * environment. `$_SERVER` is what Env's ServerConstAdapter reads, and it is
 * process-global — hence the snapshot/restore below.
 *
 * @return list<string>
 */
function ecvSimulatedEnvKeys(): array
{
    return [
        DataEncrypterFactory::PRIMARY_ENV_KEY,
        DataEncrypterFactory::PREVIOUS_ENV_KEY,
        DataEncrypterFactory::APP_ENV_KEY,
        DataEncrypterFactory::APP_PREVIOUS_ENV_KEY,
    ];
}

/**
 * Make Application::configurationIsCached() answer true for this test.
 *
 * Binds the memo the framework itself reads instead of planting a cache FILE:
 * configurationIsCached() caches its answer in the container the first time it
 * is asked, and the service provider asks during boot — so a file appearing on
 * disk once a test body is running would already be too late.
 */
function ecvPretendConfigIsCached(): void
{
    app()->instance('config_loaded_from_cache', true);
}

/**
 * Define a variable in the real process environment, which is what a cached
 * config leaves visible to env(): the environment file is never loaded, so only
 * externally defined variables remain.
 */
function ecvPutEnv(string $key, string $value): void
{
    $_SERVER[$key] = $value;
}

beforeEach(function (): void {
    Schema::dropIfExists('settings');
    Schema::dropIfExists('users');

    // Fortify::$encrypter is process-global static state; capture whatever the
    // service provider installed so a test that swaps it cannot leak into the
    // next file in the run.
    $this->ecvOriginalFortifyEncrypter = Fortify::$encrypter;

    $this->ecvOriginalEnv = [];

    foreach (ecvSimulatedEnvKeys() as $key) {
        $this->ecvOriginalEnv[$key] = $_SERVER[$key] ?? null;
    }
});

afterEach(function (): void {
    Fortify::$encrypter = $this->ecvOriginalFortifyEncrypter;

    // Restore rather than unset: a value that was already there belongs to the
    // run, and dropping it would sabotage every later test file.
    foreach ($this->ecvOriginalEnv as $key => $value) {
        if ($value === null) {
            unset($_SERVER[$key]);

            continue;
        }

        $_SERVER[$key] = $value;
    }

    Schema::dropIfExists('settings');
    Schema::dropIfExists('users');
});

/*
|--------------------------------------------------------------------------
| 1. The covered baseline
|--------------------------------------------------------------------------
*/

it('reports both surfaces as covered and kit-built when nothing overrides the encrypters', function (): void {
    ecvCreateTables();
    ecvConfigureKeys();

    $payload = ecvHealthJson();

    expect($payload['verdict'])->toBe(EncryptionHealthCommand::VERDICT_SAFE)
        ->and($payload['exit_code'])->toBe(0)
        ->and($payload['summary']['unvouched'])->toBe(0)
        ->and($payload['config_block']['present'])->toBeTrue();

    expect(ecvCoverage($payload, 'settings')['status'])->toBe(EncrypterCoverage::STATUS_COVERED)
        ->and(ecvCoverage($payload, 'settings')['kit_built'])->toBeTrue()
        ->and(ecvCoverage($payload, 'two-factor')['status'])->toBe(EncrypterCoverage::STATUS_COVERED)
        ->and(ecvCoverage($payload, 'two-factor')['kit_built'])->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| 2. A consumer's own Fortify encrypter
|--------------------------------------------------------------------------
*/

it('reports the two-factor surface as foreign when the app installed its own Fortify encrypter', function (): void {
    ecvCreateTables();
    ecvConfigureKeys();

    // Exactly what a consumer app that called Fortify::encryptUsing() before the
    // kit booted leaves behind. The kit does NOT overwrite it — see
    // StarterKitServiceProvider::configureDataEncryption().
    Fortify::$encrypter = ecvEncrypter('someone-elses-key');

    $payload = ecvHealthJson();

    $twoFactor = ecvCoverage($payload, 'two-factor');

    expect($twoFactor['status'])->toBe(EncrypterCoverage::STATUS_FOREIGN)
        ->and($twoFactor['kit_built'])->toBeFalse()
        ->and($twoFactor['detail'])->toContain('Fortify::encryptUsing()');

    // The settings surface is untouched by a Fortify swap.
    expect(ecvCoverage($payload, 'settings')['status'])->toBe(EncrypterCoverage::STATUS_COVERED);

    // And "safe to clear" is now unreachable, which is the whole point.
    expect($payload['verdict'])->toBe(EncryptionHealthCommand::VERDICT_NOT_COVERED)
        ->and($payload['safe_to_clear'])->toBeFalse()
        ->and($payload['exit_code'])->toBe(1)
        ->and($payload['summary']['unvouched'])->toBe(1);
});

it('reports the two-factor surface as covered when the app rebound Fortify onto the SAME key', function (): void {
    ecvCreateTables();
    ecvConfigureKeys();

    // Authorship differs, coverage does not: an app-built encrypter on the kit's
    // key chain is genuinely covered, and flattening it into a warning would
    // train an operator to skip the ones that matter.
    $chain = app(DataEncrypterFactory::class)->keys();
    Fortify::$encrypter = (new Encrypter($chain[0]['key'], 'AES-256-CBC'))->previousKeys(
        array_column(array_slice($chain, 1), 'key')
    );

    $twoFactor = ecvCoverage(ecvHealthJson(), 'two-factor');

    expect($twoFactor['status'])->toBe(EncrypterCoverage::STATUS_COVERED)
        ->and($twoFactor['kit_built'])->toBeFalse();
});

it('reports a partial chain when the app writes the primary key but cannot read the previous ones', function (): void {
    ecvCreateTables();

    config([
        'app.key' => ecvKey('app'),
        'app.cipher' => 'AES-256-CBC',
        'app.previous_keys' => [],
        'starter-kit.encryption' => [
            'key' => ecvKey('primary'),
            'previous_keys' => ecvKey('retired'),
            'cipher' => null,
        ],
    ]);
    app(DataEncrypterFactory::class)->flush();

    // Same write key, no previous keys: new values are fine, a value still on
    // the retired key is already unreadable there. A rekey fixes it, so this
    // must NOT block one.
    Fortify::$encrypter = ecvEncrypter('primary');

    $payload = ecvHealthJson();

    expect(ecvCoverage($payload, 'two-factor')['status'])->toBe(EncrypterCoverage::STATUS_PARTIAL)
        ->and($payload['summary']['unvouched'])->toBe(0);
});

/*
|--------------------------------------------------------------------------
| 3. The missing config block
|--------------------------------------------------------------------------
*/

it('refuses a clean verdict when the starter-kit.encryption config block is absent', function (): void {
    ecvCreateTables();

    // A published config/starter-kit.php from before the encryption release,
    // read through a cached config so mergeConfigFrom cannot restore the block.
    config([
        'app.key' => ecvKey('app'),
        'app.cipher' => 'AES-256-CBC',
        'app.previous_keys' => [],
        'starter-kit.encryption' => null,
    ]);
    app(DataEncrypterFactory::class)->flush();

    $payload = ecvHealthJson();

    expect($payload['config_block']['present'])->toBeFalse()
        ->and($payload['summary']['config_block_missing'])->toBeTrue()
        ->and($payload['verdict'])->toBe(EncryptionHealthCommand::VERDICT_NOT_COVERED)
        ->and($payload['safe_to_clear'])->toBeFalse()
        ->and($payload['exit_code'])->toBe(1);

    // The fallback is real, not cosmetic: the primary key IS APP_KEY.
    expect($payload['keys']['using_dedicated_key'])->toBeFalse()
        ->and($payload['keys']['source'])->toBe(DataEncrypterFactory::APP_ENV_KEY);

    Artisan::call('encryption:health');

    expect(Artisan::output())->toContain('ABSENT');
});

/*
|--------------------------------------------------------------------------
| 4. A CACHED config that no longer matches the environment
|--------------------------------------------------------------------------
|
| config:cache freezes every env() call into a PHP array and Laravel then stops
| loading .env at all. config() and the environment become two sources that
| drift apart in silence, and health attributes rows with the CACHED one. If it
| then says "safe to clear", the operator empties DATA_ENCRYPTION_PREVIOUS_KEYS,
| the cache is rebuilt on the next deploy, and the app comes back on a chain
| that no longer holds the key those rows were written with — with the list that
| held it already gone.
|
| Locked here: divergence, and "could not tell", both cost the clean verdict —
| but an agreeing cached install keeps it, because a warning every healthy
| production box prints for ever is a warning nobody reads.
|
*/

it('keeps safe-to-clear when the configuration is cached and the chains still agree', function (): void {
    ecvCreateTables();
    ecvConfigureKeys();

    // The environment imposes exactly what the cache resolved.
    ecvPutEnv(DataEncrypterFactory::PRIMARY_ENV_KEY, ecvKey('primary'));
    ecvPutEnv(DataEncrypterFactory::APP_ENV_KEY, ecvKey('app'));

    ecvPretendConfigIsCached();

    $payload = ecvHealthJson();

    expect($payload['config_block']['configuration_cached'])->toBeTrue()
        ->and($payload['config_block']['env_chain_diverges'])->toBeFalse()
        ->and($payload['summary']['env_chain_diverged'])->toBeFalse()
        ->and($payload['verdict'])->toBe(EncryptionHealthCommand::VERDICT_SAFE)
        ->and($payload['safe_to_clear'])->toBeTrue()
        ->and($payload['exit_code'])->toBe(0);

    Artisan::call('encryption:health');

    // No nagging on a healthy cached install.
    expect(Artisan::output())->not->toContain('config:clear');
});

it('downgrades to incomplete when the cached chain and the environment disagree', function (): void {
    ecvCreateTables();
    ecvConfigureKeys();

    // .env moved on to a rotated key; the cached config still serves the old
    // one. Nothing in the row scan can see this.
    ecvPutEnv(DataEncrypterFactory::PRIMARY_ENV_KEY, ecvKey('rotated-in-env'));
    ecvPutEnv(DataEncrypterFactory::APP_ENV_KEY, ecvKey('app'));

    ecvPretendConfigIsCached();

    $payload = ecvHealthJson();

    expect($payload['config_block']['env_chain_diverges'])->toBeTrue()
        ->and($payload['summary']['env_chain_diverged'])->toBeTrue()
        ->and($payload['verdict'])->toBe(EncryptionHealthCommand::VERDICT_INCOMPLETE)
        ->and($payload['safe_to_clear'])->toBeFalse()
        ->and($payload['exit_code'])->toBe(1);

    Artisan::call('encryption:health');

    $output = Artisan::output();

    // The fix has to be NAMED, not implied by "config is cached".
    expect($output)->toContain('config:clear')
        // The probe reads real key material out of the environment. None of it
        // may reach the report — the divergence is a boolean, nothing else.
        ->and($output)->not->toContain(ecvKey('rotated-in-env'))
        ->and($output)->not->toContain(ecvKey('primary'))
        ->and($output)->not->toContain(ecvKey('app'));
});

it('fails closed when the configuration is cached and no chain can be read from the environment', function (): void {
    ecvCreateTables();
    ecvConfigureKeys();

    // No .env in the testbench app and no variable in the process environment:
    // the chain a config:clear would leave behind cannot be determined at all.
    // "Could not check" must never resolve to "safe".
    ecvPretendConfigIsCached();

    $payload = ecvHealthJson();

    expect($payload['config_block']['env_chain_diverges'])->toBeTrue()
        ->and($payload['verdict'])->toBe(EncryptionHealthCommand::VERDICT_INCOMPLETE)
        ->and($payload['safe_to_clear'])->toBeFalse()
        ->and($payload['exit_code'])->toBe(1);
});

it('only ever downgrades: a divergence never masks a row still riding a previous key', function (): void {
    ecvCreateTables();
    ecvConfigureKeys();

    // Written under APP_KEY, which the chain keeps as a read-only fallback —
    // rekey-required, and it outranks the new signal.
    DB::table('settings')->insert([
        'group' => 'mail',
        'key' => 'password',
        'value' => ecvEncrypter('app')->encryptString('mail-secret'),
        'encrypted' => 1,
    ]);

    ecvPretendConfigIsCached();

    $payload = ecvHealthJson();

    expect($payload['summary']['env_chain_diverged'])->toBeTrue()
        ->and($payload['verdict'])->toBe(EncryptionHealthCommand::VERDICT_REKEY_REQUIRED)
        ->and($payload['exit_code'])->toBe(1);
});

it('reports no divergence at all while the configuration is not cached', function (): void {
    ecvCreateTables();
    ecvConfigureKeys();

    // A variable that contradicts config() outright. Without a cached config
    // this is not a divergence to report: config() reads the live files, so
    // there is nothing for it to be stale against, and downgrading here would
    // punish every ordinary install.
    ecvPutEnv(DataEncrypterFactory::PRIMARY_ENV_KEY, ecvKey('something-else'));

    $payload = ecvHealthJson();

    expect($payload['config_block']['configuration_cached'])->toBeFalse()
        ->and($payload['config_block']['env_chain_diverges'])->toBeNull()
        ->and($payload['summary']['env_chain_diverged'])->toBeFalse()
        ->and($payload['verdict'])->toBe(EncryptionHealthCommand::VERDICT_SAFE);
});

/*
|--------------------------------------------------------------------------
| 5. encryption:rekey refuses what it cannot re-encrypt
|--------------------------------------------------------------------------
*/

it('refuses to rekey and writes nothing when a selected surface has a foreign encrypter', function (): void {
    ecvCreateTables();
    ecvConfigureKeys();

    $stored = ecvEncrypter('app')->encryptString('mail-secret');
    DB::table('settings')->insert(['group' => 'mail', 'key' => 'password', 'value' => $stored, 'encrypted' => 1]);

    Fortify::$encrypter = ecvEncrypter('someone-elses-key');

    $result = ecvRekey();

    expect($result['status'])->not->toBe(0)
        ->and($result['output'])->toContain('Refusing to rekey')
        ->and($result['output'])->toContain('two-factor')
        ->and($result['output'])->toContain('--only=settings');

    // Fail-closed means fail EARLY: the covered surface is not half-done either.
    expect(DB::table('settings')->value('value'))->toBe($stored);
});

it('still rekeys the surfaces it does cover when the foreign one is excluded', function (): void {
    ecvCreateTables();
    ecvConfigureKeys();

    // Written under APP_KEY, which the chain keeps as a read-only fallback.
    $stored = ecvEncrypter('app')->encryptString('mail-secret');
    DB::table('settings')->insert(['group' => 'mail', 'key' => 'password', 'value' => $stored, 'encrypted' => 1]);

    Fortify::$encrypter = ecvEncrypter('someone-elses-key');

    $result = ecvRekey(['--only' => 'settings']);

    expect($result['status'])->toBe(0)
        ->and($result['output'])->not->toContain('Refusing to rekey');

    $rekeyed = DB::table('settings')->value('value');

    expect($rekeyed)->not->toBe($stored)
        ->and(ecvEncrypter('primary')->decryptString((string) $rekeyed))->toBe('mail-secret');
});

it('never rebinds or repairs the encrypter it reports on', function (): void {
    ecvCreateTables();
    ecvConfigureKeys();

    $foreign = ecvEncrypter('someone-elses-key');
    Fortify::$encrypter = $foreign;

    ecvHealthJson();
    ecvRekey();

    // Report only. A command that "fixed" this would lock every 2FA user out of
    // their account on the next login.
    expect(Fortify::$encrypter)->toBe($foreign)
        ->and(app(DataEncrypterFactory::BINDING))->toBeInstanceOf(EncrypterContract::class);
});
