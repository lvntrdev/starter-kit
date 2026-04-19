<?php

namespace App\Http\Requests\Admin\Settings;

use App\Models\Setting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTurnstileSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // secret_key only required when turning Turnstile on AND no stored
        // secret exists. Blank submissions otherwise mean "keep current".
        $secretRules = ['nullable', 'string', 'max:255'];
        if ($this->boolean('enabled')
            && ! $this->hasEffectiveSecret('turnstile.secret_key', 'services.turnstile.secret_key')) {
            $secretRules = ['required', ...$secretRules];
        }

        return [
            'enabled' => ['required', 'boolean'],
            'site_key' => ['required_if:enabled,true', 'nullable', 'string', 'max:255'],
            'secret_key' => $secretRules,
        ];
    }

    /**
     * Mirror SettingsDefaultsQuery: DB row wins, otherwise fall back to the
     * config-backed (.env) value — so the UI's `secret_key_is_set` flag and
     * the `required` validation stay consistent when the secret lives only
     * in env.
     */
    private function hasEffectiveSecret(string $settingPath, string $configKey): bool
    {
        $dbValue = Setting::getValue($settingPath);

        if (is_string($dbValue) ? $dbValue !== '' : $dbValue !== null) {
            return true;
        }

        $configValue = config($configKey);

        return is_string($configValue) ? $configValue !== '' : $configValue !== null;
    }
}
