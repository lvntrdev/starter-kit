<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation rules for avatar upload.
 *
 * Used by both ProfileController (self-upload, no {user} route param) and
 * Admin UserController ({user} route param — requires update policy, which
 * enforces both `users.update` permission and the rank-hierarchy guard).
 */
class UploadAvatarRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $actor = $this->user();

        if ($actor === null) {
            return false;
        }

        $target = $this->route('user');

        // Profile (self) flow — no {user} route binding.
        if ($target === null) {
            return true;
        }

        // Admin flow — delegate to UserPolicy::update (permission + rank).
        if ($target instanceof User) {
            return $actor->can('update', $target);
        }

        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'avatar' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
                'dimensions:max_width=4096,max_height=4096',
            ],
        ];
    }
}
