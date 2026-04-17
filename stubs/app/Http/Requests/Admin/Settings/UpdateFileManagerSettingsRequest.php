<?php

namespace App\Http\Requests\Admin\Settings;

use App\Http\Requests\BaseFormRequest;

class UpdateFileManagerSettingsRequest extends BaseFormRequest
{
    protected string $attributeNamespace = 'sk-setting';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string|int>>
     */
    public function rules(): array
    {
        return [
            'max_size_kb' => ['required', 'integer', 'min:1', 'max:1048576'],
            'accepted_mimes' => ['required', 'array', 'min:1'],
            'accepted_mimes.*' => ['string', 'max:255'],
            'allow_video' => ['required', 'boolean'],
            'allow_audio' => ['required', 'boolean'],
        ];
    }
}
