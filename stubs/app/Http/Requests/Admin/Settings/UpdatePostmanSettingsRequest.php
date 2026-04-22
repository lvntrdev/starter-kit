<?php

namespace App\Http\Requests\Admin\Settings;

use App\Models\Setting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePostmanSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.update') ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // api_key is optional as long as one is already stored; otherwise
        // the user must supply one to enable the feature.
        $apiKeyRules = ['nullable', 'string', 'max:255'];
        if (! $this->hasStoredApiKey()) {
            $apiKeyRules = ['nullable', 'string', 'max:255'];
        }

        return [
            'api_key' => $apiKeyRules,
            'workspace_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    private function hasStoredApiKey(): bool
    {
        $stored = Setting::getValue('postman.api_key');

        return is_string($stored) ? $stored !== '' : $stored !== null;
    }
}
