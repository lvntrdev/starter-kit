<?php

namespace App\Http\Requests\Admin\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFileManagerSettingsRequest extends FormRequest
{
    /**
     * MIME types that must never reach the admin settings form.
     * Mirrors UploadFileRequest::BLOCKED_MIMES so an admin cannot opt back
     * into SVG / HTML uploads from the UI.
     *
     * @var array<int, string>
     */
    private const BLOCKED_MIMES = [
        'image/svg+xml',
        'image/svg',
        'text/html',
        'application/xhtml+xml',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'max_size_kb' => ['required', 'integer', 'min:1', 'max:1048576'],
            'accepted_mimes' => ['required', 'array', 'min:1'],
            'accepted_mimes.*' => [
                'string',
                'max:255',
                'regex:/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/i',
                Rule::notIn(self::BLOCKED_MIMES),
            ],
            'allow_video' => ['required', 'boolean'],
            'allow_audio' => ['required', 'boolean'],
        ];
    }
}
