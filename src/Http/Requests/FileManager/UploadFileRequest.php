<?php

namespace Lvntr\StarterKit\Http\Requests\FileManager;

use Illuminate\Validation\Validator;
use Lvntr\StarterKit\Domain\FileManager\Concerns\ResolvesMediaModel;
use Symfony\Component\Mime\MimeTypes;

class UploadFileRequest extends FileManagerRequest
{
    use ResolvesMediaModel;

    /**
     * Baseline MIME list used when no settings are configured yet,
     * so the uploader never crashes with "mimetypes:" on a fresh install.
     *
     * @var array<int, string>
     */
    protected const DEFAULT_MIMES = [
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
    protected const BLOCKED_MIMES = [
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
        $maxSizeKb = (int) config('file-manager.settings.max_size_mb', 10) * 1024;
        $mimetypes = $this->acceptedMimes();

        $fileRules = [
            'required',
            'file',
            "max:{$maxSizeKb}",
            'mimetypes:'.implode(',', $mimetypes),
        ];

        // Content sniffing alone is not enough: the file is stored under its
        // client name and served from the public disk, so `payload.html` whose
        // bytes sniff as text/plain would come back as active content (stored
        // XSS). The client extension must be in the allowlist derived from the
        // accepted MIME types as well. Empty list = every MIME was blocked, and
        // `mimetypes:` already rejects everything — no empty rule parameters.
        $extensions = $this->acceptedExtensions();

        if ($extensions !== []) {
            $fileRules[] = 'extensions:'.implode(',', $extensions);
        }

        return [
            ...$this->contextRules(),
            'folder_id' => ['nullable', 'uuid'],
            // Opt-in "managed folder" name: when present (and folder_id is
            // not), the upload action idempotently ensures a root-level
            // folder with this name exists in the context and drops the
            // files there. Used by rich-text editor uploads to group
            // their media (e.g. "Welcome Message") without requiring the
            // client to pre-create the folder. Strict regex blocks path
            // traversal and arbitrary characters.
            'folder_name' => ['nullable', 'string', 'max:100', 'regex:/^[\p{L}\p{N} _-]+$/u'],
            'files' => ['required', 'array', 'min:1'],
            'files.*' => $fileRules,
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
            'files.*.extensions' => trans('sk-file-manager.errors.upload_invalid_type', ['types' => $extList]),
            'files.*.max' => trans('sk-file-manager.errors.upload_too_large', [
                'max' => $this->humanMaxSize(),
            ]),
            'files.*.file' => trans('sk-file-manager.errors.upload_invalid_file'),
            'files.*.required' => trans('sk-file-manager.errors.upload_invalid_file'),
        ];
    }

    /**
     * Kota kontrolü: mevcut disk kullanımı + gelen dosyalar toplamı kotayı
     * aşıyorsa `files` alanına validation hatası ekle.
     *
     * withTrashed dahil toplam kullanım, gerçek disk doluluk.
     */
    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $files = $this->file('files');
            if (! is_array($files) || $files === []) {
                return;
            }

            $incoming = 0;
            foreach ($files as $index => $file) {
                if ($file === null) {
                    continue;
                }
                $incoming += (int) $file->getSize();

                // Per-segment blocklist (shell.php.jpg): the `extensions:`
                // rule only sees the last segment. Same check the media
                // library runs on add — here it is a field error instead of
                // a FileNameNotAllowed exception, and it also holds on
                // media-library builds that predate that guard.
                $name = (string) $file->getClientOriginalName();
                if ($name !== '' && $this->hasDisallowedExtensionSegment($name)) {
                    $validator->errors()->add('files.'.$index, trans('sk-file-manager.errors.invalid_type', ['name' => $name]));
                }
            }

            $current = $this->computeStorageUsed();
            $quota = $this->storageQuotaBytes();

            if ($current + $incoming > $quota) {
                $validator->errors()->add('files', trans('sk-file-manager.errors.quota_exceeded', [
                    'used' => $this->mb($current),
                    'incoming' => $this->mb($incoming),
                    'quota' => $this->mb($quota),
                ]));
            }
        });
    }

    /**
     * Byte değerini kullanıcıya gösterilecek MB string'ine çevirir.
     * 10 MB altı için 1 ondalık basamak, üstü için tam sayıya yuvarlar.
     */
    private function mb(int $bytes): string
    {
        $mb = $bytes / (1024 * 1024);

        return $mb < 10
            ? number_format($mb, 1)
            : (string) (int) round($mb);
    }

    protected function humanMaxSize(): string
    {
        $mb = (int) config('file-manager.settings.max_size_mb', 10);

        return $mb.' MB';
    }

    /**
     * Single source of truth for "which client extensions may carry this
     * MIME type". Used both by the `extensions:` upload rule and by the
     * human-readable accepted-type list (which shows the first entry).
     *
     * A MIME missing from this map falls back to Symfony's MIME database
     * (`application/x-rar-compressed` -> `rar`, PPTX and friends), then to
     * its subtype (`application/zip` -> `zip`). Consumers that accept a MIME
     * neither knows can extend this map by overriding the method in a
     * subclassed request.
     *
     * @return array<string, array<int, string>>
     */
    protected function mimeExtensionMap(): array
    {
        return [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/gif' => ['gif'],
            'image/webp' => ['webp'],
            'image/svg+xml' => ['svg'],
            'application/pdf' => ['pdf'],
            'application/vnd.ms-excel' => ['xls'],
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
            'application/msword' => ['doc'],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
            // finfo reports most text formats as text/plain; none of these
            // is active content when served back.
            'text/plain' => ['txt', 'log', 'md', 'markdown', 'csv', 'json', 'ini', 'conf', 'yml', 'yaml'],
            'text/csv' => ['csv'],
            'video/mp4' => ['mp4'],
            'video/webm' => ['webm'],
            'video/quicktime' => ['mov'],
            'video/x-matroska' => ['mkv'],
            'video/ogg' => ['ogv'],
            'video/x-msvideo' => ['avi'],
            'video/avi' => ['avi'],
            'audio/mpeg' => ['mp3'],
            'audio/wav' => ['wav'],
            'audio/ogg' => ['ogg'],
            // MediaRecorder writes audio/webm into a plain `.webm` file, so
            // both extensions are legitimate for this MIME.
            'audio/webm' => ['weba', 'webm'],
        ];
    }

    /**
     * Flat, lowercase client-extension allowlist derived from the accepted
     * MIME types.
     *
     * Only `[a-z0-9]` tokens survive: the accepted-MIME list is admin
     * configurable, and the rule is assembled into a `extensions:a,b,c`
     * string, so a stray `,` or `|` in a derived token could otherwise
     * inject rule parameters. A dropped token simply contributes nothing to
     * the allowlist (fail closed).
     *
     * @return array<int, string>
     */
    protected function acceptedExtensions(): array
    {
        $extensions = [];

        foreach ($this->acceptedMimes() as $mime) {
            foreach ($this->extensionsFor($mime) as $extension) {
                if (preg_match('/^[a-z0-9]+$/', $extension) === 1) {
                    $extensions[] = $extension;
                }
            }
        }

        return array_values(array_unique($extensions));
    }

    protected function mimeExtensionList(): string
    {
        $exts = [];

        foreach ($this->acceptedMimes() as $mime) {
            $exts[] = $this->extensionsFor($mime)[0];
        }

        return strtoupper(implode(', ', array_unique($exts)));
    }

    /**
     * Client extensions that may carry a MIME: the kit map first, then
     * Symfony's MIME database (admin-added types such as PPTX or RAR whose
     * subtype is not an extension), then the subtype itself.
     *
     * @return array<int, string>
     */
    private function extensionsFor(string $mime): array
    {
        $mime = strtolower(trim($mime));

        return $this->mimeExtensionMap()[$mime]
            ?? (MimeTypes::getDefault()->getExtensions($mime) ?: [$this->extensionFromMime($mime)]);
    }

    /**
     * Fallback extension for a MIME that is not in the map: its subtype,
     * lowercased so it can be compared against `extensions:` parameters
     * (the validator lowercases the client extension but not the rule
     * parameters).
     */
    private function extensionFromMime(string $mime): string
    {
        $parts = explode('/', strtolower(trim($mime)));

        return trim($parts[1] ?? $parts[0]);
    }

    /**
     * @return array<int, string>
     */
    protected function acceptedMimes(): array
    {
        $raw = config('file-manager.settings.accepted_mimes', null);

        if (is_array($raw)) {
            $mimes = $raw;
        } elseif (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $mimes = is_array($decoded) ? $decoded : [];
        } else {
            $mimes = [];
        }

        if ((bool) config('file-manager.settings.allow_video', false)) {
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

        if ((bool) config('file-manager.settings.allow_audio', false)) {
            $mimes = [...$mimes, 'audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/webm'];
        }

        if ($mimes === []) {
            $mimes = self::DEFAULT_MIMES;
        }

        $mimes = array_values(array_diff($mimes, self::BLOCKED_MIMES));

        return array_values(array_unique($mimes));
    }
}
