<?php

namespace App\Http\Requests\Admin\Settings;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

class UpdateAuthSettingsRequest extends BaseFormRequest
{
    protected string $attributeNamespace = 'sk-setting';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'registration' => ['required', 'boolean'],
            'email_verification' => ['required', 'boolean'],
            'two_factor' => ['required', 'boolean'],
            'password_reset' => ['required', 'boolean'],
        ];
    }
}
