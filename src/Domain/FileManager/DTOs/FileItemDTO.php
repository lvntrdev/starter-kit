<?php

namespace Lvntr\StarterKit\Domain\FileManager\DTOs;

use Illuminate\Support\Facades\Log;
use Lvntr\StarterKit\Domain\Shared\DTOs\BaseDTO;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Shape used by the FileManager frontend for a single file entry.
 */
readonly class FileItemDTO extends BaseDTO
{
    public function __construct(
        public int $id,
        public ?string $uuid,
        public string $name,
        public string $fileName,
        public string $mimeType,
        public int $size,
        public ?string $folderId,
        public string $url,
        public ?string $publicUrl,
        public ?string $createdAt,
    ) {}

    /**
     * @param  FileManagerContextDTO|null  $context  the context the media was
     *                                               listed under. Nullable ONLY for backward compatibility with consumers
     *                                               that call this helper directly; every kit call site threads it in.
     */
    public static function fromModel(Media $media, ?FileManagerContextDTO $context = null): self
    {
        try {
            // S3 and friends: a short-lived signed URL is already the safe
            // answer, and it keeps the bytes off the PHP worker.
            $url = $media->getTemporaryUrl(now()->addMinutes(30));
        } catch (RuntimeException) {
            // Local/public disks throw here. The old fallback was
            // Media::getUrl() — a permanent, unauthenticated, non-expiring
            // public link that bypasses FileManagerAuthorizer completely and
            // keeps serving the file after a permission revoke or a move to
            // trash. Hand out the authorized in-app route instead.
            $url = self::previewUrl($media, $context);
        }

        return new self(
            id: (int) $media->id,
            uuid: $media->uuid,
            name: $media->name,
            fileName: $media->file_name,
            mimeType: (string) $media->mime_type,
            size: (int) $media->size,
            folderId: $media->folder_id,
            url: $url,
            publicUrl: self::publicUrl($media),
            createdAt: $media->created_at?->toIso8601String(),
        );
    }

    /**
     * Permanent, unauthenticated URL -- present ONLY for a disk that is already
     * publicly readable, and null everywhere else.
     *
     * $url above is deliberately session-gated, which is right for the file
     * browser but wrong for the one thing that outlives the admin session:
     * an image the editor embeds into rich text as <img src>, persisted and
     * later rendered to visitors who never authenticate. Those need a link
     * that does not depend on the author's session, so the editor reads this
     * field and falls back to $url only when there is none.
     *
     * This adds no exposure: a disk declared 'visibility' => 'public' serves
     * its bytes to anyone with the path regardless of what the API returns.
     * A private disk has no such URL, and null is the honest answer -- you
     * cannot publish from a disk that is not published.
     */
    private static function publicUrl(Media $media): ?string
    {
        if (config("filesystems.disks.{$media->disk}.visibility") !== 'public') {
            return null;
        }

        try {
            return $media->getUrl();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * URL of the authorized preview route, carrying the context as query
     * parameters — FileManagerRequest::context() reads `context` /
     * `context_id` from the query string, so a plain <img src> authenticates
     * with the session cookie and still passes authorizeRead() plus the
     * media-belongs-to-context guard.
     *
     * Two fallbacks remain, both deployment-time and neither reachable by an
     * attacker (a request cannot make the context null or unregister a route):
     * a direct consumer call that passes no context, and an app that never
     * mounted the FileManager route group. Both keep the previous behaviour
     * rather than emitting a dead URL, and both log a warning naming the file.
     */
    private static function previewUrl(Media $media, ?FileManagerContextDTO $context): string
    {
        if ($context === null) {
            Log::warning('FileItemDTO::fromModel() called without a FileManager context; falling back to the public media URL. Pass the context so the authorized preview route can be used.', [
                'media_id' => $media->getKey(),
            ]);

            return $media->getUrl();
        }

        $parameters = ['media' => $media->getKey(), 'context' => $context->context];

        if ($context->contextId !== null && $context->contextId !== '') {
            $parameters['context_id'] = $context->contextId;
        }

        try {
            return route('file-manager.files.preview', $parameters);
        } catch (RouteNotFoundException) {
            Log::warning('FileManager preview route is not registered; falling back to the public media URL. Mount the FileManager routes to stop handing out unauthenticated file links.', [
                'media_id' => $media->getKey(),
            ]);

            return $media->getUrl();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        return new static(
            id: (int) $data['id'],
            uuid: $data['uuid'] ?? null,
            name: $data['name'],
            fileName: $data['file_name'],
            mimeType: $data['mime_type'],
            size: (int) $data['size'],
            folderId: $data['folder_id'] ?? null,
            url: $data['url'],
            publicUrl: $data['public_url'] ?? null,
            createdAt: $data['created_at'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'file_name' => $this->fileName,
            'mime_type' => $this->mimeType,
            'size' => $this->size,
            'folder_id' => $this->folderId,
            'url' => $this->url,
            'public_url' => $this->publicUrl,
            'created_at' => $this->createdAt,
        ];
    }
}
