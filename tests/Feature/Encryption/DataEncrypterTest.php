<?php

/*
|--------------------------------------------------------------------------
| DataEncrypterFactory — the key-resolution contract
|--------------------------------------------------------------------------
|
| This file locks the four properties the whole feature rests on. Each one is
| here because breaking it is silent: the failure surfaces as a null setting
| value or a 2FA challenge that never accepts a valid code, weeks after the
| release that caused it.
|
|   1. No DATA_ENCRYPTION_KEY -> the primary key is APP_KEY, byte-for-byte the
|      pre-feature behaviour. A plain `composer update` must change nothing.
|   2. A dedicated key set -> APP_KEY moves to the END of the read chain, so
|      every row written before adoption still decrypts with NO command run.
|   3. DATA_ENCRYPTION_PREVIOUS_KEYS parses predictably: comma separated,
|      whitespace tolerant, empties dropped, order preserved, duplicates
|      removed (on DECODED material, so base64 and raw collapse).
|   4. A malformed key THROWS and names the env var. It is never skipped —
|      a skipped key presents recoverable rows as unreadable, and the operator
|      reflex to that is to clear the previous-key list, which is the real,
|      irreversible data loss.
|
| Assertions are on round-trip plaintext and on the resolved chain, never on
| ciphertext bytes: the IV is random, so identical plaintext encrypts
| differently every call.
|
| The harness is self-contained (same shape as
| tests/Feature/BackwardCompat/LegacyPublishedConfigTest.php) and never touches
| the database — key resolution is a pure function of config.
|
*/

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Lvntr\StarterKit\Support\Encryption\DataEncrypterFactory;

/**
 * Deterministic 32-byte key material for a given seed.
 *
 * Deterministic on purpose: a failure message that quotes a byte count stays
 * reproducible, and the same seed can be rebuilt in a second assertion to prove
 * WHICH key opened a payload.
 */
function dekBytes(string $seed): string
{
    return substr(hash('sha256', $seed, true), 0, 32);
}

/**
 * The same material in the `base64:` form an operator actually puts in .env.
 */
function dekKey(string $seed): string
{
    return 'base64:'.base64_encode(dekBytes($seed));
}

/**
 * A fresh factory. Never the container singleton: these tests change key
 * configuration between assertions, and the factory memoises its chain.
 */
function dekFactory(): DataEncrypterFactory
{
    return new DataEncrypterFactory;
}

/**
 * @return list<string>
 */
function dekSources(DataEncrypterFactory $factory): array
{
    return array_column($factory->keys(), 'source');
}

/**
 * Rebuild the framework encrypter from the CURRENT app.key.
 *
 * Testbench resolves `encrypter` at boot, so a config change after boot leaves
 * a stale instance behind — and a "legacy Crypt round-trip" assertion against a
 * stale encrypter proves nothing.
 */
function dekRebuildCrypt(): void
{
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
}

beforeEach(function (): void {
    // Testbench boots with an empty app.key; every scenario states its own key
    // configuration explicitly rather than inheriting an ambient one.
    config([
        'app.key' => dekKey('app'),
        'app.cipher' => 'AES-256-CBC',
        'app.previous_keys' => [],
        'starter-kit.encryption.key' => null,
        'starter-kit.encryption.previous_keys' => null,
        'starter-kit.encryption.cipher' => null,
    ]);

    dekRebuildCrypt();
});

/*
|--------------------------------------------------------------------------
| 1. No dedicated key — the legacy path stays byte-identical
|--------------------------------------------------------------------------
*/

it('uses APP_KEY as the primary key when no dedicated key is configured', function (): void {
    $factory = dekFactory();

    expect(dekSources($factory))->toBe(['APP_KEY'])
        ->and($factory->usingDedicatedKey())->toBeFalse()
        ->and($factory->keys()[0]['key'])->toBe(dekBytes('app'));
});

it('round-trips todays ciphertext identically with the framework Crypt', function (): void {
    $encrypter = dekFactory()->make();

    // Both directions matter. Package-written ciphertext has to stay readable by
    // anything the consumer still routes through Crypt, and every row already in
    // the database was written by Crypt.
    expect(Crypt::decryptString($encrypter->encryptString('mail-password')))->toBe('mail-password')
        ->and($encrypter->decryptString(Crypt::encryptString('mail-password')))->toBe('mail-password');
});

it('treats a blank DATA_ENCRYPTION_KEY as absent rather than as a zero-length key', function (): void {
    // The shipped .env stub writes `DATA_ENCRYPTION_KEY=` with no value. Reading
    // that as a key would throw on every encrypted read of a fresh install.
    config(['starter-kit.encryption.key' => '   ']);

    expect(dekSources(dekFactory()))->toBe(['APP_KEY']);
});

/*
|--------------------------------------------------------------------------
| 2. Adoption — APP_KEY moves to the end of the chain, nothing is orphaned
|--------------------------------------------------------------------------
*/

it('keeps APP_KEY as the LAST previous key once a dedicated key is adopted', function (): void {
    $preAdoption = dekFactory()->make()->encryptString('turnstile-secret');

    config(['starter-kit.encryption.key' => dekKey('dedicated')]);

    $factory = dekFactory();
    $sources = dekSources($factory);

    expect($sources)->toBe(['DATA_ENCRYPTION_KEY', 'APP_KEY'])
        ->and(end($sources))->toBe('APP_KEY')
        ->and($factory->usingDedicatedKey())->toBeTrue()
        // The whole backward-compatibility guarantee, in one assertion.
        ->and($factory->make()->decryptString($preAdoption))->toBe('turnstile-secret');
});

it('writes new values under the dedicated key, not under APP_KEY', function (): void {
    config(['starter-kit.encryption.key' => dekKey('dedicated')]);

    $fresh = dekFactory()->make()->encryptString('turnstile-secret');

    $appKeyOnly = new Encrypter(dekBytes('app'), 'AES-256-CBC');
    $dedicatedOnly = new Encrypter(dekBytes('dedicated'), 'AES-256-CBC');

    expect($dedicatedOnly->decryptString($fresh))->toBe('turnstile-secret')
        ->and(fn () => $appKeyOnly->decryptString($fresh))->toThrow(DecryptException::class);
});

it('keeps APP_PREVIOUS_KEYS in the chain so a part-way APP_KEY rotation is not orphaned', function (): void {
    // Inherited, not introduced: until this feature landed every encrypted
    // setting was read through the framework `encrypter` binding, which honours
    // app.previous_keys. Dropping them would lose rows silently.
    $rotated = (new Encrypter(dekBytes('old-app'), 'AES-256-CBC'))->encryptString('spaces-secret');

    config([
        'starter-kit.encryption.key' => dekKey('dedicated'),
        'app.previous_keys' => [dekKey('old-app')],
    ]);

    $factory = dekFactory();

    expect(dekSources($factory))->toBe(['DATA_ENCRYPTION_KEY', 'APP_PREVIOUS_KEYS[0]', 'APP_KEY'])
        ->and($factory->make()->decryptString($rotated))->toBe('spaces-secret');
});

/*
|--------------------------------------------------------------------------
| 3. DATA_ENCRYPTION_PREVIOUS_KEYS parsing
|--------------------------------------------------------------------------
*/

it('parses a comma separated previous-key list tolerantly and in order', function (): void {
    $a = dekKey('prev-a');
    $b = dekKey('prev-b');

    // Whitespace around entries, two empty segments, and a duplicate — the exact
    // shape a hand-edited .env line acquires over a couple of rotations.
    config([
        'starter-kit.encryption.key' => dekKey('dedicated'),
        'starter-kit.encryption.previous_keys' => "  {$a} , , {$b} ,{$a},  ",
    ]);

    $chain = dekFactory()->keys();

    expect(array_column($chain, 'source'))->toBe([
        'DATA_ENCRYPTION_KEY',
        'DATA_ENCRYPTION_PREVIOUS_KEYS[0]',
        'DATA_ENCRYPTION_PREVIOUS_KEYS[1]',
        'APP_KEY',
    ])
        // Order preserved: the index in the label counts USABLE entries, so a
        // dropped blank must not shift the key it points at.
        ->and($chain[1]['key'])->toBe(dekBytes('prev-a'))
        ->and($chain[2]['key'])->toBe(dekBytes('prev-b'));
});

it('accepts the previous-key list as an array as well as a string', function (): void {
    // A consumer may set the published config key directly instead of via .env.
    config([
        'starter-kit.encryption.key' => dekKey('dedicated'),
        'starter-kit.encryption.previous_keys' => [dekKey('prev-a'), '   ', dekKey('prev-b')],
    ]);

    expect(dekSources(dekFactory()))->toBe([
        'DATA_ENCRYPTION_KEY',
        'DATA_ENCRYPTION_PREVIOUS_KEYS[0]',
        'DATA_ENCRYPTION_PREVIOUS_KEYS[1]',
        'APP_KEY',
    ]);
});

it('decrypts with every key in the previous-key list', function (): void {
    $withA = (new Encrypter(dekBytes('prev-a'), 'AES-256-CBC'))->encryptString('written-under-a');
    $withB = (new Encrypter(dekBytes('prev-b'), 'AES-256-CBC'))->encryptString('written-under-b');

    config([
        'starter-kit.encryption.key' => dekKey('dedicated'),
        'starter-kit.encryption.previous_keys' => dekKey('prev-a').','.dekKey('prev-b'),
    ]);

    $encrypter = dekFactory()->make();

    expect($encrypter->decryptString($withA))->toBe('written-under-a')
        ->and($encrypter->decryptString($withB))->toBe('written-under-b');
});

it('collapses a previous key that repeats APP_KEY, keeping the first source label', function (): void {
    config([
        'starter-kit.encryption.key' => dekKey('dedicated'),
        'starter-kit.encryption.previous_keys' => dekKey('app'),
    ]);

    $factory = dekFactory();

    // APP_KEY is not appended a second time, and the label that survives is the
    // one that was reached first.
    expect(dekSources($factory))->toBe(['DATA_ENCRYPTION_KEY', 'DATA_ENCRYPTION_PREVIOUS_KEYS[0]'])
        ->and($factory->make()->decryptString(
            (new Encrypter(dekBytes('app'), 'AES-256-CBC'))->encryptString('legacy-row')
        ))->toBe('legacy-row');
});

it('de-duplicates on decoded material, so base64 and raw forms collapse', function (): void {
    // A 32-character ASCII key is a legal raw APP_KEY; Illuminate uses it
    // verbatim. Written base64 in one place and raw in the other it is still ONE
    // key, and a duplicate entry would misreport the chain to encryption:health.
    $raw = str_repeat('a', 32);

    config([
        'app.key' => $raw,
        'starter-kit.encryption.key' => dekKey('dedicated'),
        'starter-kit.encryption.previous_keys' => 'base64:'.base64_encode($raw),
    ]);

    expect(dekSources(dekFactory()))->toBe(['DATA_ENCRYPTION_KEY', 'DATA_ENCRYPTION_PREVIOUS_KEYS[0]']);
});

/*
|--------------------------------------------------------------------------
| 4. A malformed key throws — it is NEVER skipped
|--------------------------------------------------------------------------
*/

it('throws naming DATA_ENCRYPTION_KEY when the dedicated key is not valid base64', function (): void {
    $bad = 'base64:@@@ not base64 @@@';
    config(['starter-kit.encryption.key' => $bad]);

    expect(fn () => dekFactory()->keys())
        ->toThrow(RuntimeException::class, 'DATA_ENCRYPTION_KEY');

    try {
        dekFactory()->keys();
    } catch (RuntimeException $e) {
        // The message must not echo the value back — a .env line pasted into a
        // bug report is how key material escapes.
        expect($e->getMessage())->not->toContain('@@@');
    }
});

it('throws naming DATA_ENCRYPTION_KEY when the key length does not match the cipher', function (): void {
    $short = 'base64:'.base64_encode(str_repeat("\x01", 16));
    config(['starter-kit.encryption.key' => $short]);

    try {
        dekFactory()->keys();
        expect(false)->toBeTrue('A 16-byte key under AES-256-CBC must not resolve.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('DATA_ENCRYPTION_KEY')
            ->and($e->getMessage())->toContain('16 bytes')
            ->and($e->getMessage())->not->toContain($short)
            ->and($e->getMessage())->not->toContain(base64_encode(str_repeat("\x01", 16)));
    }
});

it('does NOT silently skip a malformed entry in the previous-key list', function (): void {
    $good = dekKey('prev-a');
    $bad = 'base64:'.base64_encode(str_repeat("\x02", 16));

    // Control: with both entries well-formed the chain has four members.
    config([
        'starter-kit.encryption.key' => dekKey('dedicated'),
        'starter-kit.encryption.previous_keys' => $good.','.dekKey('prev-b'),
    ]);
    expect(dekFactory()->keys())->toHaveCount(4);

    // Same shape, second entry malformed. Skipping it would produce a chain of
    // three and present the rows it opens as permanently unreadable — which is
    // exactly what pushes an operator into clearing the list for real.
    config(['starter-kit.encryption.previous_keys' => $good.','.$bad]);

    try {
        dekFactory()->keys();
        expect(false)->toBeTrue('A malformed previous key must throw, not be dropped from the chain.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('DATA_ENCRYPTION_PREVIOUS_KEYS[1]')
            ->and($e->getMessage())->not->toContain($bad);
    }
});

it('warns the operator not to clear the previous-key list while fixing a bad key', function (): void {
    config(['starter-kit.encryption.key' => 'base64:%%%']);

    // The message is the only guard between a typo and real data loss.
    expect(fn () => dekFactory()->keys())
        ->toThrow(RuntimeException::class, 'DATA_ENCRYPTION_PREVIOUS_KEYS');
});

it('throws naming APP_KEY when APP_KEY itself is malformed', function (): void {
    config(['app.key' => 'base64:'.base64_encode('too-short')]);

    expect(fn () => dekFactory()->keys())
        ->toThrow(RuntimeException::class, 'APP_KEY');
});

it('throws when no key is configured at all, naming both env vars', function (): void {
    config(['app.key' => '', 'starter-kit.encryption.key' => null]);

    try {
        dekFactory()->keys();
        expect(false)->toBeTrue('An unkeyed install must not resolve an encrypter.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('DATA_ENCRYPTION_KEY')
            ->and($e->getMessage())->toContain('APP_KEY');
    }
});

/*
|--------------------------------------------------------------------------
| 5. Cipher resolution — an app that changed app.cipher must keep working
|--------------------------------------------------------------------------
*/

it('follows app.cipher so a 16-byte APP_KEY install still resolves', function (): void {
    // Forcing AES-256-CBC on an AES-128-CBC app would reject its own APP_KEY and
    // throw on every encrypted read.
    config([
        'app.cipher' => 'AES-128-CBC',
        'app.key' => 'base64:'.base64_encode(substr(dekBytes('app'), 0, 16)),
    ]);

    $factory = dekFactory();

    expect($factory->cipher())->toBe('AES-128-CBC')
        ->and($factory->make()->decryptString($factory->make()->encryptString('ok')))->toBe('ok');
});

it('prefers DATA_ENCRYPTION_CIPHER when app.cipher is unset', function (): void {
    config(['starter-kit.encryption.cipher' => ' AES-256-GCM ', 'app.cipher' => null]);

    expect(dekFactory()->cipher())->toBe('AES-256-GCM');
});

it('accepts a DATA_ENCRYPTION_CIPHER that matches app.cipher up to case and whitespace', function (): void {
    config(['starter-kit.encryption.cipher' => ' aes-256-cbc ', 'app.cipher' => 'AES-256-CBC']);

    $factory = dekFactory();

    expect($factory->cipher())->toBe('aes-256-cbc')
        ->and($factory->make()->decryptString($factory->make()->encryptString('ok')))->toBe('ok');
});

it('rejects a DATA_ENCRYPTION_CIPHER that differs from app.cipher', function (): void {
    // APP_KEY always closes the read chain and Illuminate's Encrypter runs ONE
    // cipher over every key it holds, so a mismatched data cipher would decrypt
    // the APP_KEY fallback with the wrong algorithm — silently, for a CBC/GCM
    // swap of equal key length. The factory refuses instead of skipping the key.
    config(['starter-kit.encryption.cipher' => 'AES-256-GCM', 'app.cipher' => 'AES-256-CBC']);

    expect(fn () => dekFactory()->cipher())
        ->toThrow(RuntimeException::class, 'DATA_ENCRYPTION_CIPHER [AES-256-GCM] does not match app.cipher [AES-256-CBC]');
});

/*
|--------------------------------------------------------------------------
| 6. A published config that predates the `encryption` block
|--------------------------------------------------------------------------
|
| mergeConfigFrom is a SHALLOW merge. A consumer who ran `sk:publish --tag=config`
| before this release keeps a starter-kit.php with no `encryption` key at all, and
| the package copy of that array is then never consulted — every read below
| returns null. This is the population that upgrades by `composer update` alone,
| so it is the one a regression reaches first.
*/

/**
 * `config/starter-kit.php` as a pre-encryption release shipped it: whatever the
 * consumer's file happens to contain, minus any `encryption` key.
 */
function dekLegacyPublishedConfig(): array
{
    return [
        'permissions' => ['allow_unmapped' => false],
        'security' => ['csp_extra_origins' => []],
    ];
}

it('resolves on a published config that has no encryption block at all', function (): void {
    config(['starter-kit' => dekLegacyPublishedConfig()]);

    // Premise check: the whole block really is invisible, not merged in.
    expect(config('starter-kit.encryption'))->toBeNull();

    $factory = dekFactory();

    expect(dekSources($factory))->toBe(['APP_KEY'])
        ->and($factory->usingDedicatedKey())->toBeFalse()
        ->and($factory->cipher())->toBe('AES-256-CBC')
        ->and($factory->make()->decryptString($factory->make()->encryptString('mail-password')))
        ->toBe('mail-password');
});

it('still reaches the default cipher when app.cipher is missing too', function (): void {
    config(['starter-kit' => dekLegacyPublishedConfig(), 'app.cipher' => null]);

    expect(dekFactory()->cipher())->toBe(DataEncrypterFactory::DEFAULT_CIPHER);
});

it('keeps the legacy population and a fresh install on the SAME cipher', function (): void {
    // The reason config/starter-kit.php carries no literal cipher default: an
    // app that switched to AES-128-CBC must resolve the SAME cipher whether or
    // not its published config predates the encryption block. A literal default
    // in the shipped file would split the two populations, and the half that
    // read the wrong cipher would reject its own APP_KEY.
    config(['app.cipher' => 'AES-128-CBC']);

    config(['starter-kit' => dekLegacyPublishedConfig()]);
    $legacy = dekFactory()->cipher();

    $shipped = require dirname(__DIR__, 3).'/config/starter-kit.php';
    config(['starter-kit' => $shipped]);
    $fresh = dekFactory()->cipher();

    expect($shipped)->toHaveKey('encryption')
        ->and($shipped['encryption']['cipher'])->toBeNull()
        ->and($legacy)->toBe('AES-128-CBC')
        ->and($fresh)->toBe($legacy);
});
