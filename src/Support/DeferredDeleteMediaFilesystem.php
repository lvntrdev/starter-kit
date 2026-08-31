<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Support;

use Illuminate\Support\Facades\Log;
use Lvntr\StarterKit\Domain\FileManager\Actions\BulkDeleteAction;
use Lvntr\StarterKit\Domain\FileManager\Actions\DeleteFolderAction;
use Lvntr\StarterKit\Domain\FileManager\Actions\EmptyTrashAction;
use Lvntr\StarterKit\Domain\FileManager\Actions\PermanentlyDeleteItemAction;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\MediaCollections\Models\Observers\MediaObserver;
use Throwable;

/**
 * Spatie's media filesystem, with the PHYSICAL delete moved behind the commit
 * of the transaction that deleted the row.
 *
 * ## The ordering bug
 *
 * Spatie removes a media object from disk in `MediaObserver::deleted()`, which
 * fires inside `Model::delete()` — i.e. INSIDE whatever transaction the caller
 * opened. The kit's own force-delete paths open one:
 * {@see PermanentlyDeleteItemAction::forceDeleteFolder()}
 * wraps a whole folder subtree, {@see BulkDeleteAction},
 * {@see EmptyTrashAction} and
 * {@see DeleteFolderAction} do the
 * same, and every `ActionPipeline` run is transactional by default.
 *
 * So the old order was: delete the row, delete the file, THEN commit. If
 * anything after the first file rolled the transaction back — a deadlock, a
 * constraint, a failure on the tenth of fifty files — every row came back and
 * every file already removed stayed gone. The user sees their files listed and
 * every download 404s. That is unrecoverable: the row is a pointer to bytes
 * that no longer exist.
 *
 * ## The order this class enforces
 *
 * The removal is registered through `afterCommit()`, so it runs only once the
 * row's deletion is durable, and is DISCARDED when the transaction rolls back —
 * Laravel drops the callbacks with the transaction record. The framework is the
 * outbox; nothing is collected or flushed by hand.
 *
 * With no transaction open there is nothing to defer to, so those paths —
 * `file-manager:purge-trash`, a bare `$media->forceDelete()` — bypass
 * `afterCommit()` entirely and remove the object inline. Routing them through
 * it would ALSO run inline (`DatabaseTransactionsManager::addCallback()`), but
 * through the swallowing wrapper below, which would silently zero the per-row
 * failure count `file-manager:purge-trash` reports and exits non-zero on.
 *
 * ## The failure that is now possible, and why it is the right one
 *
 * A removal that fails AFTER the commit leaves a file on disk with no row: an
 * orphan. It costs quota and it is recoverable — the path is logged, and the
 * object can be swept later. The inverse (a row with no file) is not
 * recoverable at all. So a deferred removal logs and returns instead of
 * throwing; throwing out of an after-commit callback would surface at the
 * `commit()` call site as if the DELETE had failed, when it has already
 * succeeded.
 *
 * An IMMEDIATE removal (no transaction) still throws exactly as before —
 * `file-manager:purge-trash` counts those failures per row and reports them,
 * and swallowing them here would silently zero that count.
 *
 * ## Scope
 *
 * ONLY `removeAllFiles()` is overridden — the whole-object removal the delete
 * path uses. `removeFile()` / `removeResponsiveImages()` are conversion
 * housekeeping that runs outside a delete and keeps its original timing.
 *
 * @see MediaObserver::deleted()
 */
class DeferredDeleteMediaFilesystem extends Filesystem
{
    public function removeAllFiles(Media $media): void
    {
        $connection = $media->getConnection();

        try {
            $deferrable = $connection->transactionLevel() > 0;
        } catch (Throwable) {
            $deferrable = false;
        }

        // Nothing to wait for: remove now and let the failure propagate, exactly
        // as it did before this class existed.
        if (! $deferrable) {
            parent::removeAllFiles($media);

            return;
        }

        try {
            $connection->afterCommit(function () use ($media): void {
                $this->removeAllFilesAfterCommit($media);
            });
        } catch (Throwable) {
            // No transactions manager on this connection (an exotic or manually
            // constructed connection). Nothing can be deferred, so keep the
            // pre-existing behaviour rather than skipping the removal: an
            // orphaned row is worse than the ordering risk this class removes.
            parent::removeAllFiles($media);
        }
    }

    /**
     * Remove the object now that its row's deletion is durable.
     *
     * Never throws — see the class docblock. The path is logged so an operator
     * can sweep the orphan; `getPath()` is derived from the media row and holds
     * no credential.
     */
    private function removeAllFilesAfterCommit(Media $media): void
    {
        try {
            parent::removeAllFiles($media);
        } catch (Throwable $e) {
            Log::warning('starter-kit: a media object could not be removed from disk after its row was deleted; the file is now orphaned.', [
                'media_id' => $media->getKey(),
                'disk' => $media->disk,
                'path' => $this->pathFor($media),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Best-effort storage path for the log line.
     *
     * The path generator can itself throw on a half-configured install, and a
     * logger call is not a place to raise from.
     */
    private function pathFor(Media $media): ?string
    {
        try {
            return $this->getMediaDirectory($media);
        } catch (Throwable) {
            return null;
        }
    }
}
