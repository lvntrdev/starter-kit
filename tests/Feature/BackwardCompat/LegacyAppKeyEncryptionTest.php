<?php

/*
|--------------------------------------------------------------------------
| Legacy APP_KEY ciphertext × a newly adopted DATA_ENCRYPTION_KEY
|--------------------------------------------------------------------------
|
| The governing regression for the whole dedicated-key feature. Every other test
| under tests/Feature/Encryption asserts a property of the key chain; this one
| asserts the CLAIM the kit makes to the installations that already exist:
|
|   an existing install upgrades by `composer update`, optionally adopts a
|   dedicated key, runs NO command, and every value it had encrypted under
|   APP_KEY still reads back as the same plaintext.
|
| It is written against real rows rather than against the factory because the
| failure mode is invisible at the factory level: SettingService swallows the
| DecryptException and returns null, so a broken chain raises nothing — the mail
| password simply becomes empty, and an unreadable two_factor_secret locks a user
| out of their own account at the challenge step.
|
| SELF-CONTAINED HARNESS — why not DatabaseTestCase
| -------------------------------------------------
| Pest binds a test FILE to exactly one base class. tests/Pest.php already maps
| the whole Feature/BackwardCompat directory to the light TestCase, and a second
| uses() target for this file throws TestCaseAlreadyInUse. Testbench's default
| connection is already sqlite :memory: and is rebuilt per test, so the two
| tables this file needs are created inline below — the same self-contained shape
| as LegacyPublishedConfigTest. They mirror the shipped schema:
|
|   settings -> database/migrations/2026_03_14_080933_create_settings_table.php
|   users    -> stubs/database/migrations/0001_01_01_000000_create_users_table.php
|               (only the columns this regression reads; a shim, not a copy)
|
| Assertions are on round-trip plaintext and on WHICH key opens a payload, never
| on ciphertext bytes — the IV is random.
|
*/

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Fortify;
use Lvntr\StarterKit\Domain\Setting\SettingService;
use Lvntr\StarterKit\StarterKitServiceProvider;
use Lvntr\StarterKit\Tests\Stubs\TestSetting;

// SettingService writes to the App\Models\Setting FQCN, which the package test
// environment does not autoload. Same guarded alias the Settings suite uses.
if (! class_exists(Setting::class)) {
    class_alias(TestSetting::class, Setting::class);
}

function legacyBytes(string $seed): string
{
    return substr(hash('sha256', $seed, true), 0, 32);
}

function legacyEnvKey(string $seed): string
{
    return 'base64:'.base64_encode(legacyBytes($seed));
}

/**
 * Drop every memoised copy of the key configuration.
 *
 * The framework encrypter, the kit's factory + binding + facade, and the
 * settings cache each hold a decision made under the PREVIOUS key. A test that
 * forgets one of them proves nothing about the key it thinks it is testing.
 */
function legacyReloadKeys(): void
{
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
    StarterKitServiceProvider::flushDataEncrypter();
    Cache::forget(SettingService::CACHE_KEY);
}

/**
 * Adopt a dedicated key the way an operator does: edit .env, restart. No
 * command, no rewrite of a single stored row.
 */
function legacyAdoptDedicatedKey(string $seed = 'dedicated'): void
{
    config(['starter-kit.encryption.key' => legacyEnvKey($seed)]);

    legacyReloadKeys();
}

/**
 * An encrypter holding ONE key, used to prove which key a payload belongs to.
 */
function legacyEncrypterFor(string $seed): Encrypter
{
    return new Encrypter(legacyBytes($seed), 'AES-256-CBC');
}

beforeEach(function (): void {
    config([
        'app.key' => legacyEnvKey('legacy-app'),
        'app.cipher' => 'AES-256-CBC',
        'app.previous_keys' => [],
        'starter-kit.encryption.key' => null,
        'starter-kit.encryption.previous_keys' => null,
        'starter-kit.encryption.cipher' => null,
    ]);

    // Fortify::$encrypter is process-global static state that outlives a test.
    // Reset it and let the provider install its shim again, so this file is not
    // at the mercy of whatever ran before it in the same process.
    Fortify::encryptUsing(null);
    Model::encryptUsing(null);

    (new ReflectionMethod(StarterKitServiceProvider::class, 'configureDataEncryption'))
        ->invoke(new StarterKitServiceProvider(app()));

    legacyReloadKeys();

    Schema::create('settings', function (Blueprint $table): void {
        $table->id();
        $table->string('group')->index();
        $table->string('key');
        $table->text('value')->nullable();
        $table->boolean('encrypted')->default(false);
        $table->timestamps();
        $table->unique(['group', 'key']);
    });

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->text('two_factor_secret')->nullable();
        $table->text('two_factor_recovery_codes')->nullable();
        $table->timestamp('two_factor_confirmed_at')->nullable();
        $table->timestamps();
    });
});

afterEach(function (): void {
    Fortify::encryptUsing(null);
    Model::encryptUsing(null);
    StarterKitServiceProvider::flushDataEncrypter();
});

/**
 * Seed the rows exactly as the pre-feature kit wrote them: SettingService used
 * Crypt::encryptString and Fortify fell through to the Crypt facade, so both are
 * APP_KEY ciphertext.
 *
 * @return array{secret: string, codes: list<string>}
 */
function legacySeedRows(): array
{
    DB::table('settings')->insert([
        ['group' => 'mail', 'key' => 'password', 'value' => Crypt::encryptString('legacy-mail-password'), 'encrypted' => true],
        ['group' => 'storage', 'key' => 'spaces_secret', 'value' => Crypt::encryptString('legacy-spaces-secret'), 'encrypted' => true],
        // A plaintext row rides along: the `encrypted` flag decides the read
        // path, and a chain change must not touch the values it does not own.
        ['group' => 'general', 'key' => 'site_name', 'value' => 'Acme', 'encrypted' => false],
    ]);

    $codes = ['aaaa-bbbb', 'cccc-dddd', 'eeee-ffff'];

    DB::table('users')->insert([
        'name' => 'Legacy Admin',
        'email' => 'legacy@example.test',
        'password' => 'hashed',
        // Fortify serialises: encrypt()/decrypt(), not the String variants.
        'two_factor_secret' => Crypt::encrypt('LEGACYTOTPSECRET'),
        'two_factor_recovery_codes' => Crypt::encrypt(json_encode($codes)),
        'two_factor_confirmed_at' => '2026-01-01 00:00:00',
    ]);

    return ['secret' => 'LEGACYTOTPSECRET', 'codes' => $codes];
}

function legacyUserRow(): object
{
    return DB::table('users')->where('email', 'legacy@example.test')->first();
}

function legacyRawSetting(string $group, string $key): ?string
{
    return DB::table('settings')->where('group', $group)->where('key', $key)->value('value');
}

/*
|--------------------------------------------------------------------------
| 1. `composer update` alone changes nothing
|--------------------------------------------------------------------------
*/

it('reads every legacy row unchanged when no dedicated key is adopted', function (): void {
    $seeded = legacySeedRows();

    // No config change at all — the state of every existing install the moment
    // the package is upgraded.
    $settings = app(SettingService::class);

    expect($settings->getValue('mail.password'))->toBe('legacy-mail-password')
        ->and($settings->getValue('storage.spaces_secret'))->toBe('legacy-spaces-secret')
        ->and($settings->getValue('general.site_name'))->toBe('Acme')
        ->and(Fortify::currentEncrypter()->decrypt(legacyUserRow()->two_factor_secret))->toBe($seeded['secret'])
        ->and(json_decode(Fortify::currentEncrypter()->decrypt(legacyUserRow()->two_factor_recovery_codes), true))
        ->toBe($seeded['codes']);
});

/*
|--------------------------------------------------------------------------
| 2. Adoption with NO command run
|--------------------------------------------------------------------------
*/

it('still reads APP_KEY settings after a dedicated key is adopted, with no command run', function (): void {
    legacySeedRows();

    legacyAdoptDedicatedKey();

    $settings = app(SettingService::class);

    expect($settings->getValue('mail.password'))->toBe('legacy-mail-password')
        ->and($settings->getValue('storage.spaces_secret'))->toBe('legacy-spaces-secret')
        ->and($settings->getValue('general.site_name'))->toBe('Acme');
});

it('still reads the APP_KEY 2FA secret and recovery codes after adoption', function (): void {
    $seeded = legacySeedRows();

    legacyAdoptDedicatedKey();

    $user = legacyUserRow();

    expect(Fortify::currentEncrypter()->decrypt($user->two_factor_secret))->toBe($seeded['secret'])
        ->and(json_decode(Fortify::currentEncrypter()->decrypt($user->two_factor_recovery_codes), true))
        ->toBe($seeded['codes']);
});

it('returns null rather than plaintext when a sensitive row is genuinely unreadable', function (): void {
    // The guard on the assertions above. SettingService swallows a decrypt
    // failure, so if the chain were broken those tests would read null — this
    // pins what a real failure looks like, which is what makes the positive
    // assertions meaningful rather than vacuous.
    DB::table('settings')->insert([
        'group' => 'turnstile',
        'key' => 'secret_key',
        'value' => legacyEncrypterFor('a-key-nobody-has')->encryptString('unreachable'),
        'encrypted' => true,
    ]);

    legacyAdoptDedicatedKey();

    expect(app(SettingService::class)->getValue('turnstile.secret_key'))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| 3. New writes move to the dedicated key; old rows keep reading
|--------------------------------------------------------------------------
*/

it('writes a new setting under the dedicated key while legacy rows stay readable', function (): void {
    legacySeedRows();

    legacyAdoptDedicatedKey();

    app(SettingService::class)->setValue('turnstile.secret_key', 'post-adoption-secret');

    $raw = legacyRawSetting('turnstile', 'secret_key');

    expect($raw)->not->toBeNull()
        ->and($raw)->not->toBe('post-adoption-secret')
        // The write landed on DATA_ENCRYPTION_KEY...
        ->and(legacyEncrypterFor('dedicated')->decryptString($raw))->toBe('post-adoption-secret')
        // ...and specifically NOT on APP_KEY, which is the point of the feature.
        ->and(fn () => legacyEncrypterFor('legacy-app')->decryptString($raw))
        ->toThrow(DecryptException::class)
        // Meanwhile nothing written before adoption moved or broke.
        ->and(app(SettingService::class)->getValue('mail.password'))->toBe('legacy-mail-password')
        ->and(app(SettingService::class)->getValue('turnstile.secret_key'))->toBe('post-adoption-secret');
});

it('writes a new 2FA payload under the dedicated key while the legacy row stays readable', function (): void {
    $seeded = legacySeedRows();

    legacyAdoptDedicatedKey();

    $fresh = Fortify::currentEncrypter()->encrypt('POSTADOPTIONTOTP');

    DB::table('users')->insert([
        'name' => 'New Admin',
        'email' => 'new@example.test',
        'password' => 'hashed',
        'two_factor_secret' => $fresh,
    ]);

    $newRow = DB::table('users')->where('email', 'new@example.test')->first();

    expect(legacyEncrypterFor('dedicated')->decrypt($newRow->two_factor_secret))->toBe('POSTADOPTIONTOTP')
        ->and(fn () => legacyEncrypterFor('legacy-app')->decrypt($newRow->two_factor_secret))
        ->toThrow(DecryptException::class)
        ->and(Fortify::currentEncrypter()->decrypt(legacyUserRow()->two_factor_secret))->toBe($seeded['secret']);
});

/*
|--------------------------------------------------------------------------
| 4. The reason the feature exists: APP_KEY rotation stops destroying data
|--------------------------------------------------------------------------
*/

it('keeps post-adoption data readable across an APP_KEY rotation', function (): void {
    legacySeedRows();
    legacyAdoptDedicatedKey();

    app(SettingService::class)->setValue('turnstile.secret_key', 'post-adoption-secret');
    $fresh2fa = Fortify::currentEncrypter()->encrypt('POSTADOPTIONTOTP');

    // `php artisan key:generate` on a server migration. Before this feature that
    // single command silently destroyed every value above.
    config(['app.key' => legacyEnvKey('rotated-app')]);
    legacyReloadKeys();

    expect(app(SettingService::class)->getValue('turnstile.secret_key'))->toBe('post-adoption-secret')
        ->and(Fortify::currentEncrypter()->decrypt($fresh2fa))->toBe('POSTADOPTIONTOTP')
        // Honest about the cost: rows written BEFORE adoption belong to the old
        // APP_KEY, so discarding it still loses them. That is exactly what the
        // runbook's previous-key step exists to prevent.
        ->and(app(SettingService::class)->getValue('mail.password'))->toBeNull();
});

it('recovers pre-adoption rows when the retired APP_KEY is kept in the previous-key list', function (): void {
    legacySeedRows();
    legacyAdoptDedicatedKey();

    // The documented server-migration step, in one .env line.
    config([
        'app.key' => legacyEnvKey('rotated-app'),
        'starter-kit.encryption.previous_keys' => legacyEnvKey('legacy-app'),
    ]);
    legacyReloadKeys();

    expect(app(SettingService::class)->getValue('mail.password'))->toBe('legacy-mail-password')
        ->and(app(SettingService::class)->getValue('storage.spaces_secret'))->toBe('legacy-spaces-secret')
        ->and(Fortify::currentEncrypter()->decrypt(legacyUserRow()->two_factor_secret))->toBe('LEGACYTOTPSECRET');
});
