<?php

/*
|--------------------------------------------------------------------------
| Data encrypter wiring — container binding, DataCrypt facade, Fortify shim
|--------------------------------------------------------------------------
|
| Companion to DataEncrypterTest, which covers key RESOLUTION. This file covers
| the WIRING around it: the lazy singleton, the Fortify encrypter shim and its
| no-clobber precedence, the runtime key swap, and the redaction guarantees.
|
| Helper names carry a `wire` prefix on purpose — a Pest test file declares its
| helpers at global scope for the whole process, so a bare `raw()` or `k()`
| would become a fatal redeclare the day another test file wants the same name.
|
*/

use Illuminate\Container\Container;
use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Laravel\Fortify\Fortify;
use Lvntr\StarterKit\StarterKitServiceProvider;
use Lvntr\StarterKit\Support\Encryption\DataCrypt;
use Lvntr\StarterKit\Support\Encryption\DataEncrypterFactory;

function wireBytes(string $seed): string
{
    return substr(hash('sha256', $seed, true), 0, 32);
}

function wireKey(string $seed): string
{
    return 'base64:'.base64_encode(wireBytes($seed));
}

function wireFlush(): void
{
    StarterKitServiceProvider::flushDataEncrypter();
}

// Testbench boots with an EMPTY app.key, so each test installs one explicitly
// and rebuilds the framework encrypter from it.
function wireAppKey(string $key): void
{
    config([
        'app.key' => $key,
        'app.cipher' => 'AES-256-CBC',
        'app.previous_keys' => [],
        'starter-kit.encryption.key' => null,
        'starter-kit.encryption.previous_keys' => null,
    ]);

    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
    wireFlush();
}

beforeEach(function () {
    wireAppKey(wireKey('app'));
});

afterEach(function () {
    Fortify::encryptUsing(null);
    Model::encryptUsing(null);
    wireFlush();
});

it('1. resolves the binding as a lazy singleton', function () {
    expect(app()->resolved(DataEncrypterFactory::BINDING))->toBeFalse()
        ->and(app(DataEncrypterFactory::BINDING))->toBe(app(DataEncrypterFactory::BINDING))
        ->and(app(DataEncrypterFactory::BINDING))->toBeInstanceOf(Encrypter::class);
});

it('2. installs the kit shim as the Fortify current encrypter', function () {
    $shim = Fortify::currentEncrypter();

    expect($shim)->toBeInstanceOf(EncrypterContract::class)
        ->and($shim)->not->toBeInstanceOf(Encrypter::class)
        ->and($shim->decrypt($shim->encrypt('totp-secret')))->toBe('totp-secret');
});

it('3. does not resolve the encrypter until the shim is actually called', function () {
    Fortify::encryptUsing(null);
    wireFlush();

    $provider = new StarterKitServiceProvider(app());
    (new ReflectionMethod($provider, 'configureDataEncryption'))->invoke($provider);

    expect(app()->resolved(DataEncrypterFactory::BINDING))->toBeFalse();

    Fortify::currentEncrypter()->encrypt('x');

    expect(app()->resolved(DataEncrypterFactory::BINDING))->toBeTrue();
});

it('4. round-trips with the framework Crypt when no dedicated key is set', function () {
    $legacy = Crypt::encryptString('mail-password');
    expect(DataCrypt::decryptString($legacy))->toBe('mail-password');

    $fresh = DataCrypt::encryptString('mail-password');
    expect(Crypt::decryptString($fresh))->toBe('mail-password');
});

it('5. reads a legacy APP_KEY 2FA payload through the Fortify shim', function () {
    $legacy = Crypt::encrypt('legacy-2fa-secret');
    expect(Fortify::currentEncrypter()->decrypt($legacy))->toBe('legacy-2fa-secret');
});

it('6. still decrypts pre-adoption ciphertext after a dedicated key is adopted', function () {
    $preSetting = DataCrypt::encryptString('turnstile-secret');
    $pre2fa = Fortify::currentEncrypter()->encrypt('pre-adoption-2fa');

    config(['starter-kit.encryption.key' => wireKey('dedicated')]);
    wireFlush();

    expect(app(DataEncrypterFactory::class)->usingDedicatedKey())->toBeTrue()
        ->and(DataCrypt::decryptString($preSetting))->toBe('turnstile-secret')
        ->and(Fortify::currentEncrypter()->decrypt($pre2fa))->toBe('pre-adoption-2fa');

    $post = DataCrypt::encryptString('turnstile-secret');
    expect(fn () => Crypt::decryptString($post))->toThrow(Exception::class);
});

it('7. keeps APP_PREVIOUS_KEYS in the read chain, matching the framework encrypter', function () {
    $old = wireBytes('old-app-key');
    $rotated = (new Encrypter($old, 'AES-256-CBC'))->encryptString('spaces-secret');

    wireAppKey(wireKey('new-app-key'));
    config(['app.previous_keys' => ['base64:'.base64_encode($old)]]);
    wireFlush();

    expect(DataCrypt::decryptString($rotated))->toBe('spaces-secret')
        ->and(array_column(app(DataEncrypterFactory::class)->keys(), 'source'))
        ->toContain('APP_PREVIOUS_KEYS[0]');
});

it('8. does not clobber a consumer that already called Fortify::encryptUsing', function () {
    $consumer = new Encrypter(wireBytes('consumer-fortify'), 'AES-256-CBC');
    Fortify::encryptUsing($consumer);

    $provider = new StarterKitServiceProvider(app());
    (new ReflectionMethod($provider, 'configureDataEncryption'))->invoke($provider);

    expect(Fortify::currentEncrypter())->toBe($consumer);
});

it('9. does not clobber a consumer that called Model::encryptUsing', function () {
    Fortify::encryptUsing(null);
    $consumer = new Encrypter(wireBytes('consumer-model'), 'AES-256-CBC');
    Model::encryptUsing($consumer);

    $provider = new StarterKitServiceProvider(app());
    (new ReflectionMethod($provider, 'configureDataEncryption'))->invoke($provider);

    expect(Fortify::$encrypter)->toBeNull()
        ->and(Fortify::currentEncrypter())->toBe($consumer);
});

it('10. never goes stale: the shim follows a runtime key swap', function () {
    $shim = Fortify::currentEncrypter();
    $shim->encrypt('warm the singleton');

    config(['starter-kit.encryption.key' => wireKey('swapped')]);
    wireFlush();

    $after = $shim->encrypt('post-swap');
    $dedicated = new Encrypter(wireBytes('swapped'), 'AES-256-CBC');

    expect($dedicated->decrypt($after))->toBe('post-swap');
});

it('11. does not throw at wiring time when no key is configured at all', function () {
    wireAppKey('');
    Fortify::encryptUsing(null);

    $provider = new StarterKitServiceProvider(app());
    (new ReflectionMethod($provider, 'configureDataEncryption'))->invoke($provider);

    expect(Fortify::$encrypter)->not->toBeNull();
    expect(fn () => Fortify::currentEncrypter()->encrypt('x'))->toThrow(RuntimeException::class);
});

it('12. flushDataEncrypter clears the facade cache as well', function () {
    $before = DataCrypt::getFacadeRoot();

    config(['starter-kit.encryption.key' => wireKey('facade-swap')]);
    wireFlush();

    expect(DataCrypt::getFacadeRoot())->not->toBe($before);
});

it('13. keeps key material out of var_dump output', function () {
    ob_start();
    var_dump(Fortify::currentEncrypter());
    $dump = (string) ob_get_clean();

    expect($dump)->toContain('key material withheld')
        ->and($dump)->not->toContain(wireBytes('app'));
});

it('14. flushDataEncrypter never installs a bare container as the global one', function () {
    $app = app();
    wireFlush();

    expect(Container::getInstance())->toBe($app);
});
