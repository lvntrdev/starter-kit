<?php

namespace App\Http\Requests\Admin\Log;

use App\Enums\RoleEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a bulk-delete payload for log files.
 * Authorization: only system_admin (the route already enforces this — this
 * is defence in depth in case the route middleware ever gets misconfigured).
 */
class DeleteLogFilesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(RoleEnum::SystemAdmin) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'filenames' => ['required', 'array', 'min:1', 'max:200'],
            'filenames.*' => ['required', 'string', 'regex:/^[A-Za-z0-9._-]+\.log$/'],
        ];
    }
}
