<?php

namespace App\Http\Requests\Admin\Settings;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

class UpdateTurnstileSettingsRequest extends BaseFormRequest
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
            'enabled' => ['required', 'boolean'],
            'site_key' => ['required_if:enabled,true', 'nullable', 'string', 'max:255'],
            'secret_key' => ['required_if:enabled,true', 'nullable', 'string', 'max:255'],
        ];
    }
}
