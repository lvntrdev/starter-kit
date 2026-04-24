<?php

namespace App\Http\Requests\Admin\Settings;

use App\Support\HtmlSanitizer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateGeneralSettingsRequest extends FormRequest
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
        return [
            'app_name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'string', 'max:100'],
            'languages' => ['required', 'array', 'min:1'],
            'languages.*' => ['required', 'string', 'max:10'],
            'welcome_message' => ['nullable', 'string', 'max:65535'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('welcome_message')) {
            return;
        }

        $cleaned = HtmlSanitizer::clean((string) $this->input('welcome_message'));

        $this->merge([
            'welcome_message' => $cleaned === '' ? null : $cleaned,
        ]);
    }
}
