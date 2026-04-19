<?php

namespace App\Http\Requests\Api\User;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validation rules for updating a user via API.
 */
class UpdateUserRequest extends FormRequest
{
    /**
     * Delegates to UserPolicy::update which enforces the `users.update`
     * permission plus the rank-hierarchy guard so a lower-ranked actor
     * cannot mutate a higher-ranked target (e.g. admin → system_admin).
     */
    public function authorize(): bool
    {
        $target = $this->route('user');
        $actor = $this->user();

        if (! $target instanceof User || $actor === null) {
            return false;
        }

        return $actor->can('update', $target);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($this->route('user'))],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive', 'banned'])],
        ];
    }
}
