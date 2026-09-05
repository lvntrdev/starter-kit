<?php

namespace Lvntr\StarterKit\Domain\FileManager\Concerns;

use Illuminate\Support\Facades\Storage;
use Lvntr\StarterKit\Domain\FileManager\DTOs\FileManagerContextDTO;
use Lvntr\StarterKit\Exceptions\DomainRuleException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Single source of truth for "serve this media row to the caller".
 *
 * Both serving actions (download, preview) go through here so the context
 * guard cannot drift apart between them. The guard is the SECOND half of the
 * authorization story and it is not optional:
 *
 *   1. The controller calls FileManagerAuthorizer::authorizeRead() — may this
 *      actor read files in this context at all?
 *   2. This guard — does the requested media row actually BELONG to that
 *      context? Without it, any actor with read rights on their own context
 *      could hand the route a foreign media id and stream someone else's file
 *      (IDOR). The collection check additionally keeps non-FileManager media
 *      (avatars, form attachments) out of these routes.
 *
 * Both conditions are checked at the data-access boundary — right before the
 * bytes are read off the disk — not at the route.
 */
trait ServesContextMedia
{
    /**
     * @param  array<string, string|null>  $headers  merged last, so a caller
     *                                               may override Content-Type
     *
     * @throws DomainRuleException when the media row is outside the context
     */
    protected function streamContextMedia(
        FileManagerContextDTO $context,
        Media $media,
        string $disposition,
        array $headers = [],
    ): BinaryFileResponse|StreamedResponse {
        if (
            $media->collection_name !== 'files'
            || $media->model_type !== $context->ownerType
            || (string) $media->model_id !== $context->ownerId
        ) {
            throw new DomainRuleException(__('sk-file-manager.errors.file_out_of_context'));
        }

        $disk = Storage::disk($media->disk);
        $path = $media->getPathRelativeToRoot();
        $headers = [
            'Content-Type' => $media->mime_type,
            ...$headers,
        ];

        // A StreamedResponse answers every Range request with the full body and
        // a 200 -- so seeking in the inline <video>/<audio> preview is dead, and
        // Safari refuses to start playback at all. BinaryFileResponse::prepare()
        // does Accept-Ranges/Content-Range/206 for us, but it needs a real
        // filesystem path, which only the local driver has. Remote drivers keep
        // the streamed path.
        if (config("filesystems.disks.{$media->disk}.driver") === 'local') {
            return response()
                ->file($disk->path($path), $headers)
                ->setContentDisposition($disposition, $media->file_name);
        }

        // FilesystemAdapter::download() is literally response(..., 'attachment'),
        // so routing both dispositions through response() keeps the download
        // path byte-for-byte identical while letting preview ask for 'inline'.
        return $disk->response($path, $media->file_name, $headers, $disposition);
    }
}
