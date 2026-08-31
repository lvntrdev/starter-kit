<?php

namespace Lvntr\StarterKit\Domain\Setting;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Lvntr\StarterKit\Support\Encryption\DataCrypt;
use Lvntr\StarterKit\Support\HtmlSanitizer;

/**
 * Service: Centralized read/write operations for application settings.
 *
 * Encapsulates encryption, caching, and bulk operations that were
 * previously static methods on the Setting model.
 */
class SettingService
{
    /**
     * Keys that must be stored encrypted.
     *
     * @var list<string>
     */
    private array $sensitiveKeys;

    /**
     * Keys that hold rich-text HTML and must be sanitized before storage.
     * Centralising the rule here guarantees sanitization even if a future
     * write path bypasses the FormRequest layer (e.g. tinker, command, job).
     *
     * @var list<string>
     */
    private const HTML_SAFE_KEYS = [
        'general.welcome_message',
    ];

    public function __construct()
    {
        // Fallback for consumers that have not published (or run an outdated)
        // config/settings.php — the file is app-owned and NOT merged by the
        // service provider, so this list is the only safety net there.
        //
        // DIVERGENCE GUARD: this array MUST stay identical to the
        // `sensitive_keys` list in stubs/config/settings.php. If a key is
        // added there without being added here, consumers without the
        // published config write that secret to the DB as PLAINTEXT.
        // Parity is enforced by tests/Feature/Settings/SensitiveKeysFallbackTest.php.
        $this->sensitiveKeys = config('settings.sensitive_keys', [
            'mail.password',
            'storage.spaces_secret',
            'storage.aws_secret',
            'turnstile.secret_key',
            'postman.api_key',
            'apidog.access_token',
        ]);
    }

    /**
     * Get a setting value by "group.key" notation.
     *
     * Reads through the cached allGrouped() snapshot so hot paths (e.g.
     * upload validation) do not issue a query per lookup.
     */
    public function getValue(string $path, mixed $default = null): mixed
    {
        [$group, $key] = $this->parsePath($path);

        $grouped = $this->allGrouped();

        return $grouped[$group][$key] ?? $default;
    }

    /**
     * Set a setting value by "group.key" notation.
     */
    public function setValue(string $path, mixed $value): void
    {
        [$group, $key] = $this->parsePath($path);

        $value = $this->normalizeValue($path, $value);
        $isSensitive = in_array($path, $this->sensitiveKeys, true);

        Setting::query()->updateOrCreate(
            ['group' => $group, 'key' => $key],
            [
                'value' => $isSensitive && $value !== null ? DataCrypt::encryptString((string) $value) : $value,
                'encrypted' => $isSensitive,
            ],
        );

        $this->forgetCacheAfterCommit();

        $this->logSettingsChange([$path]);
    }

    /**
     * Seed a default value for a setting, but only if the key does not yet exist.
     *
     * Mirrors setValue()'s normalization + encryption rules so that seeded
     * sensitive keys (mail.password, storage.*_secret, …) are stored
     * encrypted-at-rest exactly like values written through the admin panel —
     * never as plaintext. Unlike setValue() this NEVER overwrites an existing
     * row, so re-running a seeder preserves admin-edited values.
     *
     * Intended for seeders/installers; centralising the rule here guarantees a
     * single write path even when values originate from config/.env.
     */
    public function seedDefault(string $group, string $key, mixed $value): void
    {
        $path = "{$group}.{$key}";

        $value = $this->normalizeValue($path, $value);
        $isSensitive = in_array($path, $this->sensitiveKeys, true);

        Setting::query()->firstOrCreate(
            ['group' => $group, 'key' => $key],
            [
                'value' => $isSensitive && $value !== null ? DataCrypt::encryptString((string) $value) : $value,
                'encrypted' => $isSensitive,
            ],
        );
    }

    /**
     * Get all settings for a group as a key-value array.
     *
     * Reads through the cached allGrouped() snapshot (see getValue()).
     *
     * @return array<string, mixed>
     */
    public function getGroup(string $group): array
    {
        return $this->allGrouped()[$group] ?? [];
    }

    /**
     * Bulk-set settings for a group with a single cache clear.
     *
     * All upserts run inside one DB transaction so a mid-loop failure cannot
     * leave the group half-written.
     *
     * @param  array<string, mixed>  $values
     */
    public function setGroup(string $group, array $values): void
    {
        DB::transaction(function () use ($group, $values): void {
            foreach ($values as $key => $value) {
                $path = "{$group}.{$key}";
                [$g, $k] = $this->parsePath($path);

                $value = $this->normalizeValue($path, $value);
                $isSensitive = in_array($path, $this->sensitiveKeys, true);

                Setting::query()->updateOrCreate(
                    ['group' => $g, 'key' => $k],
                    [
                        'value' => $isSensitive && $value !== null ? DataCrypt::encryptString((string) $value) : $value,
                        'encrypted' => $isSensitive,
                    ],
                );
            }
        });

        $this->forgetCacheAfterCommit();

        $this->logSettingsChange(array_map(
            static fn (int|string $key): string => "{$group}.{$key}",
            array_keys($values),
        ));
    }

    /**
     * Drop the cached snapshot once the surrounding transaction commits.
     *
     * setGroup() opens its own transaction, but callers wrap it in an OUTER one
     * (UpdateAuthSettingsAction, for instance, needs the group write and the 2FA
     * revoke to land together). Forgetting the key inline would clear the cache
     * while the outer transaction is still open, so a concurrent reader could
     * miss, re-read the PRE-write rows, and cache them for another hour.
     * DB::afterCommit() defers to the outermost commit and — when no transaction
     * is active at all — runs the callback immediately, so the single-write path
     * behaves exactly as before.
     */
    private function forgetCacheAfterCommit(): void
    {
        DB::afterCommit(static fn () => Cache::forget('settings'));
    }

    /**
     * Record a settings change on the `audit` activity channel.
     *
     * Only the changed KEY PATHS are stored — never the values. Setting values
     * can hold secrets (mail.password, storage.*_secret, turnstile/postman/apidog
     * keys); writing them to activity_log would leak the secret into a second,
     * unencrypted store. Recording which keys changed — attributed to the
     * auto-resolved causer — is the useful, safe audit signal.
     *
     * Skipped when there is no authenticated causer: settings written from
     * seeders/installers/queue jobs (e.g. the Postman collection-id sync) are
     * configuration bootstrapping, not audited admin actions.
     *
     * @param  list<string>  $keys  Full "group.key" paths that were written.
     */
    private function logSettingsChange(array $keys): void
    {
        if ($keys === [] || ! auth()->check()) {
            return;
        }

        activity('audit')
            ->event('updated')
            ->withProperties(['keys' => array_values($keys)])
            ->log('Settings updated');
    }

    /**
     * Get all settings grouped by group name (cached).
     *
     * @return array<string, array<string, mixed>>
     */
    public function allGrouped(): array
    {
        return Cache::remember('settings', 3600, function () {
            // Read via the query builder, NOT Eloquent. allGrouped() can run from
            // a service-provider booting() callback (the auth-security config
            // bridge), which fires BEFORE any provider boot() — and Eloquent's
            // static connection resolver is only set in DatabaseServiceProvider::
            // boot(). A Setting::all() there fatals with "Call to a member
            // function connection() on null". The query builder resolves the
            // connection from the container's `db` binding, available that early.
            // The `encrypted` flag arrives as a raw int (no model cast), so the
            // truthiness check below is equivalent to the boolean-cast model.
            $grouped = [];

            foreach (DB::table('settings')->get() as $row) {
                $grouped[$row->group][$row->key] = $this->decryptIfNeeded($row->value, (bool) $row->encrypted);
            }

            return $grouped;
        });
    }

    /**
     * Decrypt a setting value if it is marked as encrypted.
     */
    private function decryptIfNeeded(mixed $value, bool $encrypted): mixed
    {
        if ($encrypted && $value !== null) {
            try {
                return DataCrypt::decryptString((string) $value);
            } catch (DecryptException $e) {
                // An unreadable row is a real operational event (wrong/rotated
                // key, corrupted ciphertext) and the null we return here can be
                // cached for an hour, so it must not stay silent. The CIPHERTEXT
                // and the plaintext are both withheld — only the failure is
                // logged. Everything else (a misconfigured cipher, a driver or
                // programming error) is NOT swallowed: it propagates so the
                // fault surfaces where it happens instead of degrading every
                // encrypted setting to its env/default fallback.
                Log::warning('starter-kit: an encrypted setting could not be decrypted; falling back to null.', [
                    'reason' => $e->getMessage(),
                ]);

                return null;
            }
        }

        return $value;
    }

    /**
     * Apply per-key normalization before persistence. Currently only HTML
     * sanitization for rich-text keys; can grow into a small pipeline if
     * other coercions are needed later.
     */
    private function normalizeValue(string $path, mixed $value): mixed
    {
        if (in_array($path, self::HTML_SAFE_KEYS, true) && is_string($value)) {
            $cleaned = HtmlSanitizer::clean($value);

            return $cleaned === '' ? null : $cleaned;
        }

        return $value;
    }

    /**
     * Parse "group.key" path into [group, key].
     *
     * @return array{0: string, 1: string}
     */
    private function parsePath(string $path): array
    {
        $parts = explode('.', $path, 2);

        if (count($parts) !== 2) {
            throw new \InvalidArgumentException("Setting path must be in 'group.key' format, got: {$path}");
        }

        return [$parts[0], $parts[1]];
    }
}
