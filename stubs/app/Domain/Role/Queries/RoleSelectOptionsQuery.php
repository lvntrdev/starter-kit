<?php

namespace App\Domain\Role\Queries;

use App\Enums\RoleEnum;
use App\Models\Role;
use App\Models\User;

/**
 * Query: Return available roles as select options, filtered by hierarchy.
 *
 * system_admin sees all roles.
 * Other users see only roles at their level or below (sort_order >= theirs).
 */
class RoleSelectOptionsQuery
{
    /**
     * @return list<array{label: string, value: string}>
     */
    public function get(User $user): array
    {
        $query = Role::query()->orderBy('sort_order');

        if (! $user->hasRole(RoleEnum::SystemAdmin)) {
            $userMinSortOrder = $user->roles->min('sort_order');

            // Actor has no role at all (e.g. direct-permission user) — they
            // must not be able to assign any role. Casting null → 0 here
            // would open the hierarchy to every role including system_admin.
            if ($userMinSortOrder === null) {
                return [];
            }

            $query->where('sort_order', '>=', (int) $userMinSortOrder);
        }

        return $query->get()->map(fn (Role $role) => [
            'label' => ucfirst(str_replace('_', ' ', $role->name)),
            'value' => $role->name,
        ])->values()->all();
    }
}
