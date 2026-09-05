<?php

namespace Lvntr\StarterKit\Domain\FileManager\Actions;

use Lvntr\StarterKit\Domain\FileManager\Concerns\ServesContextMedia;
use Lvntr\StarterKit\Domain\FileManager\DTOs\FileManagerContextDTO;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * In-app, authorized replacement for a raw public media URL.
 *
 * FileItemDTO used to fall back to Media::getUrl() whenever the disk has no
 * temporary-URL support (every local or public disk). That URL is permanent,
 * unauthenticated and unexpiring — it bypasses FileManagerAuthorizer entirely,
 * survives a permission revoke, and keeps working after the file is moved to
 * trash. This action serves the same bytes behind exactly the authorization
 * download() runs: authorizeRead() at the controller plus the shared context
 * guard in ServesContextMedia.
 *
 * The ONLY difference from DownloadFileAction is presentation — the browser is
 * asked to render the file in place instead of saving it. That difference is
 * also the risk: an inline response is rendered ON THE APPLICATION ORIGIN, so
 * active content (HTML, SVG, XHTML) served inline would be stored XSS against
 * the admin session. UploadFileRequest::BLOCKED_MIMES already refuses those on
 * upload, but media rows can arrive from other sources (older installs, a
 * consumer calling addMedia() directly, an admin-widened accepted-MIME list),
 * so this action does NOT trust the stored MIME type to be harmless:
 *
 *   - only a conservative allowlist of passively-rendered types is served
 *     inline; everything else falls back to 'attachment', which browsers never
 *     execute;
 *   - a non-allowlisted type is additionally relabelled application/octet-stream
 *     so a stored 'text/html' cannot be honoured even if a future change lets
 *     the disposition through;
 *   - X-Content-Type-Options: nosniff stops content sniffing from promoting a
 *     text/plain response back into markup.
 */
class PreviewFileAction extends FileManagerAction
{
    use ServesContextMedia;

    /**
     * Types a browser renders passively. Deliberately narrow: image, audio and
     * video decoders plus the built-in PDF viewer and plain text. SVG is NOT
     * here — it is an XML document that can carry script.
     *
     * @var array<int, string>
     */
    private const INLINE_SAFE_EXACT = [
        'application/pdf',
        // Neither is ever parsed as markup, and nosniff keeps it that way.
        // text/plain also covers md/json/yml/log: finfo reports those as
        // text/plain, which is what UploadFileRequest stores.
        'text/plain',
        'text/csv',
    ];

    /**
     * @var array<int, string>
     */
    private const INLINE_SAFE_PREFIXES = [
        'image',
        'audio',
        'video',
    ];

    /**
     * Substrings that disqualify a type no matter what it claims to be, so a
     * crafted value such as image+xml or text-html-ish subtypes can never take
     * the inline branch.
     *
     * @var array<int, string>
     */
    private const NEVER_INLINE_MARKERS = [
        'svg',
        'xml',
        'html',
        'script',
    ];

    public function execute(FileManagerContextDTO $context, Media $media): BinaryFileResponse|StreamedResponse
    {
        $mime = $this->normalizeMime($media->mime_type);
        $inline = $this->isInlineSafe($mime);

        return $this->streamContextMedia(
            $context,
            $media,
            $inline ? 'inline' : 'attachment',
            [
                'Content-Type' => $inline ? $mime : 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * Lowercase the essence and drop any parameter (charset, boundary) — the
     * allowlist compares essences, and an unparsed parameter would otherwise
     * make every comparison miss and silently downgrade real previews.
     */
    private function normalizeMime(?string $mime): string
    {
        $essence = explode(';', (string) $mime, 2)[0];

        return strtolower(trim($essence));
    }

    private function isInlineSafe(string $mime): bool
    {
        if ($mime === '') {
            return false;
        }

        foreach (self::NEVER_INLINE_MARKERS as $marker) {
            if (str_contains($mime, $marker)) {
                return false;
            }
        }

        if (in_array($mime, self::INLINE_SAFE_EXACT, true)) {
            return true;
        }

        $type = explode('/', $mime, 2)[0];

        return in_array($type, self::INLINE_SAFE_PREFIXES, true);
    }
}
