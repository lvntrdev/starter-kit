<?php

namespace App\Domain\Shared\Services;

use App\Models\Definition;
use Illuminate\Support\Facades\Cache;

/**
 * Service that provides DB-based definitions for the frontend.
 * All definition data (labels, severities) is stored in the definitions table.
 */
class DefinitionService
{
    private const CACHE_KEY = 'definitions';

    private const CACHE_TTL = 60 * 60 * 24; // 24 hours

    /**
     * Get all definitions, optionally filtered by keys.
     *
     * @param  string[]|null  $keys  Filter by specific keys, or null for all
     * @return array<string, array<int, array{value: string|int, label: string, severity: string|null, icon: string|null}>>
     */
    public function all(?array $keys = null): array
    {
        $definitions = $this->getFromDatabase();

        if ($keys) {
            $definitions = array_intersect_key($definitions, array_flip($keys));
        }

        return $definitions;
    }

    /**
     * Get a single definition group by key.
     *
     * @return array<int, array{value: string|int, label: string, severity: string|null, icon: string|null}>
     */
    public function get(string $key): array
    {
        return $this->all([$key])[$key] ?? [];
    }

    /**
     * Get DB definitions grouped by key, with caching and locale awareness.
     *
     * @return array<string, array<int, array{value: string|int, label: string, severity: string|null, icon: string|null}>>
     */
    private function getFromDatabase(): array
    {
        $locale = app()->getLocale();
        $cacheKey = self::CACHE_KEY.':'.$locale;

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($locale) {
            return Definition::query()
                ->where('is_active', true)
                ->where('visibility', true)
                ->where('lang', $locale)
                ->orderBy('key')
                ->orderBy('order')
                ->get()
                ->groupBy('key')
                ->map(fn ($items) => $items->map(fn (Definition $item) => [
                    'value' => $item->value,
                    'label' => $item->label,
                    'severity' => $item->severity,
                    'icon' => $item->icon,
                ])->values()->all())
                ->all();
        });
    }

    /**
     * Clear the definitions cache for all locales.
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY.':en');
        Cache::forget(self::CACHE_KEY.':tr');
    }
}
