<?php

namespace App\Http\Requests\Admin\Role;

use App\Enums\RoleEnum;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validation rules for creating a new role in admin panel.
 */
class StoreRoleRequest extends BaseFormRequest
{
    protected string $attributeNamespace = 'sk-role';

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9_]+$/', 'unique:roles,name'],
            'display_name' => ['required', 'array'],
            'display_name.*' => ['required', 'string', 'max:255'],
            'group' => ['nullable', 'string', 'max:255'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ];
    }

    /**
     * Non-system_admin users can only assign permissions they themselves possess.
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
            $data['permissions'] = array_values(array_intersect(
                $data['permissions'] ?? [],
                $userPermissions,
            ));
        }

        return $data;
    }
}
