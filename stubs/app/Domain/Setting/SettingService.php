<?php

namespace App\Domain\Setting;

use App\Models\Setting;
use App\Support\HtmlSanitizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

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
        $this->sensitiveKeys = config('settings.sensitive_keys', [
            'mail.password',
            'storage.spaces_secret',
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
                'value' => $isSensitive && $value !== null ? Crypt::encryptString((string) $value) : $value,
                'encrypted' => $isSensitive,
            ],
        );

        Cache::forget('settings');
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
                        'value' => $isSensitive && $value !== null ? Crypt::encryptString((string) $value) : $value,
                        'encrypted' => $isSensitive,
                    ],
                );
            }
        });

        Cache::forget('settings');
    }

    /**
     * Get all settings grouped by group name (cached).
     *
     * @return array<string, array<string, mixed>>
     */
    public function allGrouped(): array
    {
        return Cache::remember('settings', 3600, function () {
            $all = Setting::all();
            $grouped = [];

            foreach ($all as $setting) {
                $grouped[$setting->group][$setting->key] = $this->decryptIfNeeded($setting);
            }

            return $grouped;
        });
    }

    /**
     * Decrypt a setting value if it is marked as encrypted.
     */
    private function decryptIfNeeded(Setting $setting): mixed
    {
        $value = $setting->value;

        if ($setting->encrypted && $value !== null) {
            try {
                $value = Crypt::decryptString($value);
            } catch (\Exception) {
                $value = null;
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
