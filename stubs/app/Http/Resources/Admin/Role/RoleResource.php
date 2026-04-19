<?php

namespace App\Http\Resources\Admin\Role;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Role
 */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'display_name' => $this->display_name,
            'group' => $this->group,
            'sort_order' => $this->sort_order,
            'guard_name' => $this->guard_name,
            'seeded_permissions' => $this->seeded_permissions,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('name')),
        ];
    }
}
