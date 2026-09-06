<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $native_name
 * @property string $direction
 * @property string|null $flag
 * @property bool $is_active
 * @property bool $is_default
 * @property string|null $fallback_code
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ContentLanguage extends Model
{
    /**
     * Cache key for the active { code: name } map shared to the frontend
     * (see HandleInertiaRequests::availableContentLocales). Kept here so the
     * writer (middleware) and the invalidator (booted events) can never drift.
     */
    public const AVAILABLE_CACHE_KEY = 'content_languages:available';

    protected $fillable = [
        'code',
        'name',
        'native_name',
        'direction',
        'flag',
        'is_active',
        'is_default',
        'fallback_code',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Flush the shared content-locale cache whenever a row changes, so content
     * language CRUD reflects immediately instead of after the ~1h TTL. Covers
     * every write path (Actions, seeder, sibling-demotion mass updates).
     */
    protected static function booted(): void
    {
        $forget = static fn () => Cache::forget(self::AVAILABLE_CACHE_KEY);

        static::saved($forget);
        static::deleted($forget);
    }
}
