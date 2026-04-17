<?php

namespace App\Http\Requests\Admin\Settings;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

class UpdateMailSettingsRequest extends BaseFormRequest
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
            'mailer' => ['required', 'string', 'in:smtp,sendmail,mailgun,postmark,resend,ses,log'],
            'host' => ['required_if:mailer,smtp', 'nullable', 'string', 'max:255'],
            'port' => ['required_if:mailer,smtp', 'nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['required_if:mailer,smtp', 'nullable', 'string', 'max:255'],
            'password' => ['required_if:mailer,smtp', 'nullable', 'string', 'max:255'],
            'encryption' => ['required_if:mailer,smtp', 'string', 'in:none,tls,ssl'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:255'],
        ];
    }
}
