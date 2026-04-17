<?php

namespace App\Http\Requests\Admin\Role;

use App\Enums\RoleEnum;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Validation rules for updating an existing role in admin panel.
 */
class UpdateRoleRequest extends BaseFormRequest
{
    protected string $attributeNamespace = 'sk-role';

    /**
     * Users can only update roles below their own hierarchy level (higher sort_order).
     * system_admin bypasses this check via Gate::before.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $role = $this->route('role');

        if ($user->hasRole(RoleEnum::SystemAdmin)) {
            return true;
        }

        $userMinSortOrder = (int) $user->roles->min('sort_order');

        return $role->sort_order > $userMinSortOrder;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9_]+$/', Rule::unique('roles')->ignore($this->route('role'))],
            'display_name' => ['required', 'array'],
            'display_name.*' => ['required', 'string', 'max:255'],
            'group' => ['nullable', 'string', 'max:255'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ];
    }

    /**
     * Non-system_admin users can only assign permissions they themselves possess.
     * Permissions outside the user's scope are preserved as-is on the role.
     *
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);

        if ($key !== null) {
            return $data;
        }

        $user = $this->user();

        if (! $user->hasRole(RoleEnum::SystemAdmin)) {
            $userPermissions = $user->getAllPermissions()->pluck('name')->all();
            $role = $this->route('role');
            $currentRolePermissions = $role->permissions->pluck('name')->all();

            // Only keep submitted permissions the user actually owns
            $controlledSubmitted = array_intersect($data['permissions'] ?? [], $userPermissions);

            // Preserve permissions outside the user's scope
            $outsideScope = array_diff($currentRolePermissions, $userPermissions);

            $data['permissions'] = array_values(array_unique(array_merge($controlledSubmitted, $outsideScope)));
        }

        return $data;
    }
}
