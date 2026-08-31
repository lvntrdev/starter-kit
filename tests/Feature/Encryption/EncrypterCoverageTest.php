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

beforeEach(function (): void {
    Schema::dropIfExists('settings');
    Schema::dropIfExists('users');

    // Fortify::$encrypter is process-global static state; capture whatever the
    // service provider installed so a test that swaps it cannot leak into the
    // next file in the run.
    $this->ecvOriginalFortifyEncrypter = Fortify::$encrypter;
});

afterEach(function (): void {
    Fortify::$encrypter = $this->ecvOriginalFortifyEncrypter;

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
| 4. encryption:rekey refuses what it cannot re-encrypt
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
