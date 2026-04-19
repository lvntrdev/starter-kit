<?php

namespace App\Http\Requests\FileManager;

use App\Models\Setting;

class UploadFileRequest extends FileManagerRequest
{
    /**
     * Baseline MIME list used when no settings are configured yet,
     * so the uploader never crashes with "mimetypes:" on a fresh install.
     *
     * @var array<int, string>
     */
    private const DEFAULT_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/plain',
        'text/csv',
    ];

    /**
     * MIME types that must never be accepted regardless of admin settings.
     * SVG can embed <script>/onload/foreignObject JavaScript and becomes
     * stored XSS when served from the public disk without sanitization.
     *
     * @var array<int, string>
     */
    private const BLOCKED_MIMES = [
        'image/svg+xml',
        'image/svg',
        'text/html',
        'application/xhtml+xml',
    ];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxSizeKb = (int) Setting::getValue('file_manager.max_size_kb', 10240);
        $mimetypes = $this->acceptedMimes();

        return [
            ...$this->contextRules(),
            'folder_id' => ['nullable', 'uuid'],
            'files' => ['required', 'array', 'min:1'],
            'files.*' => [
                'required',
                'file',
                "max:{$maxSizeKb}",
                'mimetypes:'.implode(',', $mimetypes),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $files = $this->file('files');
        if (! is_array($files)) {
            return [];
        }

        $attributes = [];
        foreach ($files as $index => $file) {
            if ($file === null) {
                continue;
            }
            $attributes["files.{$index}"] = $file->getClientOriginalName();
        }

        return $attributes;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $extList = $this->mimeExtensionList();

        return [
            'files.*.mimetypes' => trans('sk-file-manager.errors.upload_invalid_type', ['types' => $extList]),
            'files.*.max' => trans('sk-file-manager.errors.upload_too_large', [
                'max' => $this->humanMaxSize(),
            ]),
            'files.*.file' => trans('sk-file-manager.errors.upload_invalid_file'),
            'files.*.required' => trans('sk-file-manager.errors.upload_invalid_file'),
        ];
    }

    private function humanMaxSize(): string
    {
        $kb = (int) Setting::getValue('file_manager.max_size_kb', 10240);
        if ($kb >= 1024) {
            return number_format($kb / 1024, $kb % 1024 === 0 ? 0 : 1).' MB';
        }

        return $kb.' KB';
    }

    private function mimeExtensionList(): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'application/pdf' => 'pdf',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/quicktime' => 'mov',
            'video/x-matroska' => 'mkv',
            'video/ogg' => 'ogv',
            'video/x-msvideo' => 'avi',
            'video/avi' => 'avi',
            'audio/mpeg' => 'mp3',
            'audio/wav' => 'wav',
            'audio/ogg' => 'ogg',
            'audio/webm' => 'weba',
        ];

        $exts = [];
        foreach ($this->acceptedMimes() as $mime) {
            $exts[] = $map[$mime] ?? explode('/', $mime)[1] ?? $mime;
        }

        return strtoupper(implode(', ', array_unique($exts)));
    }

    /**
     * @return array<int, string>
     */
    private function acceptedMimes(): array
    {
        $raw = Setting::getValue('file_manager.accepted_mimes', null);

        if (is_array($raw)) {
            $mimes = $raw;
        } elseif (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $mimes = is_array($decoded) ? $decoded : [];
        } else {
            $mimes = [];
        }

        if ((bool) Setting::getValue('file_manager.allow_video', false)) {
            $mimes = [
                ...$mimes,
                'video/mp4',
                'video/webm',
                'video/quicktime',
                'video/x-matroska',
                'video/ogg',
                'video/x-msvideo',
                'video/avi',
            ];
        }

        if ((bool) Setting::getValue('file_manager.allow_audio', false)) {
            $mimes = [...$mimes, 'audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/webm'];
        }

        if ($mimes === []) {
            $mimes = self::DEFAULT_MIMES;
        }

        $mimes = array_values(array_diff($mimes, self::BLOCKED_MIMES));

        return array_values(array_unique($mimes));
    }
}
