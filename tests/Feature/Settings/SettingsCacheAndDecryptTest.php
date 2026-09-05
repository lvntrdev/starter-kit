<?php

/*
|--------------------------------------------------------------------------
| SettingService — cache invalidation timing + decrypt failure handling
|--------------------------------------------------------------------------
|
| A) SK-SYS-005a: setGroup() clears the cached snapshot only once the OUTER
|    transaction commits. Clearing it inline lets a concurrent reader miss,
|    re-read the pre-write rows, and cache them for another hour.
| B) A rolled-back outer transaction must not clear the cache at all — the
|    snapshot still matches what the database holds.
| C) With no transaction open at all the clear happens immediately, exactly
|    as it did before.
| D) SK-SYS-012: a DecryptException degrades one value to null and is logged;
|    anything else propagates instead of silently poisoning the cache with a
|    null the app then treats as "unset".
| E) The v2 cache holds RAW rows, never a decrypted secret — a sensitive value
|    stays out of the serialized snapshot even though the plaintext still
|    round-trips through getValue().
| F) The legacy v1 `settings` key (which cached DECRYPTED values) is dropped
|    the first time the v2 snapshot is built, so a plaintext copy never
|    lingers for the rest of its hour.
|
*/

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lvntr\StarterKit\Domain\Setting\SettingService;
use Lvntr\StarterKit\Support\Encryption\DataCrypt;
use Lvntr\StarterKit\Tests\Stubs\TestSetting;

// SettingService writes to the App\Models\Setting FQCN; the package test
// environment does not autoload App\, so the stub is aliased in (same pattern
// as the other Settings tests).
if (! class_exists(Setting::class)) {
    class_alias(TestSetting::class, Setting::class);
}

beforeEach(function (): void {
    Cache::flush();
    $this->service = new SettingService;
});

// ── A) the clear waits for the outer commit ─────────────────────────────────

it('defers the settings cache clear to the outer transaction commit', function (): void {
    $this->service->setGroup('general', ['site_name' => 'Before']);

    expect($this->service->allGrouped()['general']['site_name'])->toBe('Before');
    expect(Cache::has(SettingService::CACHE_KEY))->toBeTrue();

    DB::transaction(function (): void {
        $this->service->setGroup('general', ['site_name' => 'After']);

        // Still cached MID-transaction: a reader that misses here would cache
        // the pre-write row for an hour.
        expect(Cache::has(SettingService::CACHE_KEY))->toBeTrue();
    });

    expect(Cache::has(SettingService::CACHE_KEY))->toBeFalse();
    expect($this->service->allGrouped()['general']['site_name'])->toBe('After');
});

// ── B) a rolled-back write leaves the cache alone ───────────────────────────

it('does not clear the settings cache when the outer transaction rolls back', function (): void {
    $this->service->setGroup('general', ['site_name' => 'Kept']);
    $this->service->allGrouped();

    expect(Cache::has(SettingService::CACHE_KEY))->toBeTrue();

    try {
        DB::transaction(function (): void {
            $this->service->setGroup('general', ['site_name' => 'Discarded']);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(Cache::has(SettingService::CACHE_KEY))->toBeTrue();
    expect($this->service->allGrouped()['general']['site_name'])->toBe('Kept');
});

// ── C) no transaction: unchanged, immediate ─────────────────────────────────

it('clears the settings cache immediately when no transaction is open', function (): void {
    $this->service->setValue('general.site_name', 'First');
    $this->service->allGrouped();

    expect(Cache::has(SettingService::CACHE_KEY))->toBeTrue();

    $this->service->setValue('general.site_name', 'Second');

    expect(Cache::has(SettingService::CACHE_KEY))->toBeFalse();
    expect($this->service->getValue('general.site_name'))->toBe('Second');
});

// ── D) decrypt failures ─────────────────────────────────────────────────────

it('degrades an undecryptable setting to null and logs it without the ciphertext', function (): void {
    $this->service->setValue('mail.password', 'super-secret');

    $stored = DB::table('settings')->where('group', 'mail')->where('key', 'password')->value('value');
    expect($stored)->not->toBe('super-secret');

    DB::table('settings')
        ->where('group', 'mail')
        ->where('key', 'password')
        ->update(['value' => 'not-a-valid-payload']);

    Cache::flush();

    Log::spy();

    expect($this->service->getValue('mail.password'))->toBeNull();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'could not be decrypted')
                // The ciphertext never reaches the log.
                && ! str_contains((string) json_encode($context), 'not-a-valid-payload');
        });
});

it('propagates a non-decrypt failure instead of caching a null', function (): void {
    $this->service->setValue('mail.password', 'super-secret');

    Cache::flush();

    DataCrypt::shouldReceive('decryptString')
        ->once()
        ->andThrow(new RuntimeException('DATA_ENCRYPTION_CIPHER does not match app.cipher'));

    expect(fn () => $this->service->getValue('mail.password'))
        ->toThrow(RuntimeException::class, 'DATA_ENCRYPTION_CIPHER does not match app.cipher');

    // The snapshot IS cached — decryption happens AFTER the cache returns, so
    // what landed there is the raw ciphertext row, never the null (or the
    // exception) this read produced. Every later read re-attempts the decrypt.
    expect(Cache::has(SettingService::CACHE_KEY))->toBeTrue();
});

it('still returns null for a genuine DecryptException', function (): void {
    $this->service->setValue('mail.password', 'super-secret');

    Cache::flush();

    DataCrypt::shouldReceive('decryptString')
        ->once()
        ->andThrow(new DecryptException('The MAC is invalid.'));

    expect($this->service->getValue('mail.password'))->toBeNull();
});

// ── E) the cached snapshot never carries a sensitive value in plaintext ─────

it('never stores a sensitive value in the cached snapshot as plaintext', function (): void {
    $this->service->setGroup('mail', ['password' => 'plain-secret-XYZ']);

    $this->service->allGrouped();

    /** @var list<array{group: string, key: string, value: mixed, encrypted: bool}> $rows */
    $rows = Cache::get(SettingService::CACHE_KEY);

    expect(str_contains(serialize($rows), 'plain-secret-XYZ'))->toBeFalse();

    $row = collect($rows)->first(
        fn (array $row): bool => $row['group'] === 'mail' && $row['key'] === 'password'
    );

    expect($row)->not->toBeNull()
        ->and($row['encrypted'])->toBeTrue()
        ->and($row['value'])->not->toBe('plain-secret-XYZ');

    expect($this->service->getValue('mail.password'))->toBe('plain-secret-XYZ');
});

// ── F) the legacy v1 key is dropped the first time v2 is built ─────────────

it('drops the legacy v1 settings cache key the first time the v2 snapshot is built', function (): void {
    Cache::put('settings', ['legacy' => true], 3600);

    $this->service->allGrouped();

    expect(Cache::has('settings'))->toBeFalse();
    expect(Cache::has(SettingService::CACHE_KEY))->toBeTrue();
});
