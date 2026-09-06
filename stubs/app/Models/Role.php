<?php

namespace App\Models;

use Lvntr\StarterKit\Traits\HasActivityLogging;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Custom Role model extending Spatie's Role with display_name JSON cast.
 *
 * @property array<string, string>|null $display_name
 * @property string|null $group
 * @property string|null $color
 * @property int $sort_order
 * @property array<int, string>|null $seeded_permissions
 */
class Role extends SpatieRole
{
    use HasActivityLogging;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'display_name' => 'array',
            'seeded_permissions' => 'array',
        ];
    }
}
