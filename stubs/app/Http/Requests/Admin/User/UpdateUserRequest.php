<?php

namespace App\Http\Requests\Admin\User;

use App\Domain\Role\Queries\RoleSelectOptionsQuery;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validation rules for updating an existing user in admin panel.
 */
class UpdateUserRequest extends FormRequest
{
    /**
     * Delegates to UserPolicy::update which enforces `users.update` plus the
     * rank-hierarchy guard (lower-ranked actors cannot edit higher-ranked
     * targets). The role validation rule below separately prevents privilege
     * escalation via role assignment.
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
        $allowedRoles = collect(app(RoleSelectOptionsQuery::class)->get($this->user()))
            ->pluck('value')
            ->all();

        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($this->route('user'))],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'status' => ['required', 'string', Rule::in(['active', 'inactive', 'banned'])],
            'role' => ['required', 'string', Rule::in($allowedRoles)],
        ];
    }
}
