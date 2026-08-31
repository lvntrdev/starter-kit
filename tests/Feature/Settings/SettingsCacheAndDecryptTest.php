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
    expect(Cache::has('settings'))->toBeTrue();

    DB::transaction(function (): void {
        $this->service->setGroup('general', ['site_name' => 'After']);

        // Still cached MID-transaction: a reader that misses here would cache
        // the pre-write row for an hour.
        expect(Cache::has('settings'))->toBeTrue();
    });

    expect(Cache::has('settings'))->toBeFalse();
    expect($this->service->allGrouped()['general']['site_name'])->toBe('After');
});

// ── B) a rolled-back write leaves the cache alone ───────────────────────────

it('does not clear the settings cache when the outer transaction rolls back', function (): void {
    $this->service->setGroup('general', ['site_name' => 'Kept']);
    $this->service->allGrouped();

    expect(Cache::has('settings'))->toBeTrue();

    try {
        DB::transaction(function (): void {
            $this->service->setGroup('general', ['site_name' => 'Discarded']);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(Cache::has('settings'))->toBeTrue();
    expect($this->service->allGrouped()['general']['site_name'])->toBe('Kept');
});

// ── C) no transaction: unchanged, immediate ─────────────────────────────────

it('clears the settings cache immediately when no transaction is open', function (): void {
    $this->service->setValue('general.site_name', 'First');
    $this->service->allGrouped();

    expect(Cache::has('settings'))->toBeTrue();

    $this->service->setValue('general.site_name', 'Second');

    expect(Cache::has('settings'))->toBeFalse();
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

    expect(Cache::has('settings'))->toBeFalse();
});

it('still returns null for a genuine DecryptException', function (): void {
    $this->service->setValue('mail.password', 'super-secret');

    Cache::flush();

    DataCrypt::shouldReceive('decryptString')
        ->once()
        ->andThrow(new DecryptException('The MAC is invalid.'));

    expect($this->service->getValue('mail.password'))->toBeNull();
});
