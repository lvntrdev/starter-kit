<?php

namespace App\Http\Resources\Admin\User;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'initials' => $this->initials,
            'email' => $this->email,
            'status' => $this->status,
            'avatar_url' => $this->avatar_url,
            'email_verified_at' => $this->email_verified_at,
            'created_at' => format_date($this->created_at),
            'updated_at' => format_date($this->updated_at),

            // Conditional: only when loaded
            'role' => $this->whenLoaded('roles', fn () => $this->roles->first()?->name),
        ];
    }
}
