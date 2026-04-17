<?php

namespace App\Http\Requests\Admin\Settings;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Contracts\Validation\ValidationRule;

class UpdateStorageSettingsRequest extends BaseFormRequest
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
            'media_disk' => ['required', 'string', 'in:local,do,s3'],
            'spaces_key' => ['nullable', 'string', 'max:255'],
            'spaces_secret' => ['nullable', 'string', 'max:255'],
            'spaces_region' => ['nullable', 'string', 'max:50'],
            'spaces_bucket' => ['nullable', 'string', 'max:255'],
            'spaces_endpoint' => ['nullable', 'url', 'max:255'],
            'spaces_url' => ['nullable', 'url', 'max:255'],
            'aws_key' => ['nullable', 'string', 'max:255'],
            'aws_secret' => ['nullable', 'string', 'max:255'],
            'aws_region' => ['nullable', 'string', 'max:50'],
            'aws_bucket' => ['nullable', 'string', 'max:255'],
            'aws_url' => ['nullable', 'url', 'max:255'],
            'aws_endpoint' => ['nullable', 'url', 'max:255'],
        ];
    }
}
