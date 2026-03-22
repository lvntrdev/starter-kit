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
            'gender' => $this->gender,
            'avatar_url' => $this->avatar_url,
            'email_verified_at' => $this->email_verified_at,
            'created_at' => format_date($this->created_at),
            'updated_at' => format_date($this->updated_at),

            // Conditional: only when loaded
            'role' => $this->whenLoaded('roles', fn () => $this->roles->first()?->name),
            'identity_document_media' => $this->when(
                $this->relationLoaded('media'),
                function () {
                    $media = $this->getFirstMedia('identity_document');

                    return $media ? [
                        'id' => $media->id,
                        'name' => $media->file_name,
                        'url' => $this->identity_document_url,
                        'size' => $media->size,
                        'mime_type' => $media->mime_type,
                    ] : null;
                },
            ),
        ];
    }
}
