<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Lvntr\StarterKit\Domain\Shared\Services\DefinitionService;

/**
 * @property int $id
 * @property string $key
 * @property string $value
 * @property string $label
 * @property string|null $explanation
 * @property string|null $severity
 * @property string|null $icon
 * @property bool $is_active
 * @property int $order
 * @property bool $visibility
 * @property string $lang
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Definition extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'key',
        'value',
        'label',
        'explanation',
        'severity',
        'icon',
        'is_active',
        'order',
        'visibility',
        'lang',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'visibility' => 'boolean',
            'order' => 'integer',
        ];
    }

    /**
     * Flush the definition cache whenever a row changes so definition CRUD
     * reflects immediately instead of after the ~1h TTL (mirrors
     * ContentLanguage::booted()). Covers every Eloquent write path:
     * create/update, soft delete, restore and force delete. Bulk upsert
     * (the DefinitionSeeder) bypasses model events, so the seeder still
     * flushes manually after its upsert.
     */
    protected static function booted(): void
    {
        $flush = static fn () => app(DefinitionService::class)->clearCache();

        static::saved($flush);
        static::deleted($flush);
        static::restored($flush);
        static::forceDeleted($flush);
    }
}
