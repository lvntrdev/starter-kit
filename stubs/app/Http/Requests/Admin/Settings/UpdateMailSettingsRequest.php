<?php

namespace App\Http\Requests\Admin\Settings;

use App\Models\Setting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMailSettingsRequest extends FormRequest
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
        // Password is only required when switching to SMTP AND no stored value
        // already exists. Blank submissions otherwise mean "keep current".
        $passwordRules = ['nullable', 'string', 'max:255'];
        if ($this->input('mailer') === 'smtp'
            && ! $this->hasEffectiveSecret('mail.password', 'mail.mailers.smtp.password')) {
            $passwordRules = ['required', ...$passwordRules];
        }

        return [
            'mailer' => ['required', 'string', 'in:smtp,sendmail,mailgun,postmark,resend,ses,log'],
            'host' => ['required_if:mailer,smtp', 'nullable', 'string', 'max:255'],
            'port' => ['required_if:mailer,smtp', 'nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['required_if:mailer,smtp', 'nullable', 'string', 'max:255'],
            'password' => $passwordRules,
            'encryption' => ['required_if:mailer,smtp', 'string', 'in:none,tls,ssl'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * Mirror SettingsDefaultsQuery: DB row wins, otherwise fall back to the
     * config-backed (.env) value — so the UI's `password_is_set` flag and the
     * `required` validation stay consistent when the secret lives only in env.
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
