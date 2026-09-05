<?php

namespace Lvntr\StarterKit\Http\Requests\FileManager;

use Illuminate\Validation\Validator;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class RenameFileRequest extends FileManagerRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            ...$this->contextRules(),
            'name' => [
                'required',
                'string',
                'max:255',
                'regex:/^[^\/\\\\<>:"|?*\x00-\x1f]+$/',
            ],
        ];
    }

    /**
     * A rename must not change what the stored file *is*. The upload guard
     * checked the client extension against the sniffed content once, and the
     * media library physically renames the file when `file_name` changes, so
     * a rename to `.html`/`.svg`/`.php` would turn a validated image into
     * active content served from the public disk. The extension therefore
     * has to stay the same (case-insensitively) and no segment of the new
     * name may be a disallowed extension.
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $name = $this->input('name');

            // `required|string` already rejected a missing or non-string name;
            // after-hooks still run, so do not touch it here (an array would
            // fault on the string conversion and turn the 422 into a 500).
            if (! is_string($name) || $name === '') {
                return;
            }

            $blocked = $this->hasDisallowedExtensionSegment($name);
            $media = $this->route('media');

            if (! $blocked && $media instanceof Media) {
                $blocked = strtolower(pathinfo($name, PATHINFO_EXTENSION))
                    !== strtolower(pathinfo((string) $media->file_name, PATHINFO_EXTENSION));
            }

            if ($blocked) {
                $validator->errors()->add('name', trans('sk-file-manager.errors.invalid_type', ['name' => $name]));
            }
        });
    }
}
