<?php

namespace Lvntr\StarterKit\Console\Commands;

use BadMethodCallException;
use Error;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Throwable;

class PurgeFileManagerTrashCommand extends Command implements Isolatable
{
    protected $signature = 'file-manager:purge-trash
        {--days= : Items older than this many days are permanently deleted; defaults to the configured trash retention period}
        {--chunk=500 : Rows loaded per round trip (1-5000)}';

    /**
     * Yalnızca FileManager'a ait dosyaları (collection_name = 'files') kalıcı olarak siler.
     * Avatar, logo, editor gibi diğer koleksiyonlar bu komut tarafından etkilenmez.
     */
    protected $description = 'Permanently delete file manager trash items (collection: files) older than the configured number of days.';

    /**
     * Cache lock held for the duration of a run.
     *
     * Two schedulers (or an operator racing the cron) purging at once would hand
     * the same rows to two forceDelete() calls, and the physical file behind the
     * loser is already gone by the time it runs — so the second pass logs a
     * failure for work that actually succeeded. One run at a time.
     */
    private const LOCK_KEY = 'starter-kit:file-manager:purge-trash';

    private const LOCK_TTL_SECONDS = 3600;

    public function handle(): int
    {
        $lock = $this->acquireLock();

        if ($lock === false) {
            $this->components->warn('Another file-manager:purge-trash run is still in progress — skipping this one.');

            return Command::SUCCESS;
        }

        try {
            return $this->purge();
        } finally {
            $lock?->release();
        }
    }

    private function purge(): int
    {
        // --days verilmezse admin ayarlarından (DB → config) gelen saklama
        // süresini kullan; o da yoksa 7 güne düş.
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('file-manager.settings.trash_retention_days', 7);
        $days = max(1, $days);
        $cutoff = now()->subDays($days);

        $chunk = max(1, min(5000, (int) $this->option('chunk')));

        /** @var class-string<Model> $mediaModel */
        $mediaModel = config('media-library.media_model', 'Spatie\\MediaLibrary\\MediaCollections\\Models\\Media');

        /** @var class-string<Model> $folderModel */
        $folderModel = config('file-manager.models.folder', 'App\\Models\\FileFolder');

        // Yalnızca 'files' koleksiyonunu kapsar; avatar/logo/editor koleksiyonları dokunulmaz.
        [$files, $fileFailures] = $this->forceDeleteAll(
            $mediaModel::onlyTrashed()
                ->where('collection_name', 'files')
                ->where('deleted_at', '<', $cutoff),
            $chunk,
        );

        [$folders, $folderFailures] = $this->forceDeleteAll(
            $folderModel::onlyTrashed()
                ->where('deleted_at', '<', $cutoff),
            $chunk,
        );

        // Legacy temizliği: trash yalnızca 'files' koleksiyonuna aittir.
        // Eski sürümlerde avatar/logo/form eki silmeleri de soft-delete'e
        // düşüyordu; bu satırlar trash UI'da görünmez, restore yolu yoktur
        // ve kotayı şişirir. Yaş şartı olmadan kalıcı süpürülür — model
        // forceDelete'i Spatie observer'ı tetikler, fiziksel dosya da gider.
        [$orphans, $orphanFailures] = $this->forceDeleteAll(
            $mediaModel::onlyTrashed()
                ->where('collection_name', '!=', 'files'),
            $chunk,
        );

        $this->info("Purged {$files} file(s) and {$folders} folder(s) from trash (older than {$days} days).");

        if ($orphans > 0) {
            $this->info("Swept {$orphans} orphaned non-FileManager media record(s) from trash (no age limit).");
        }

        $failures = $fileFailures + $folderFailures + $orphanFailures;

        if ($failures > 0) {
            // Non-zero so a scheduler or CI step does not read a partial purge as
            // a clean one. The rows that failed stay in trash and are retried on
            // the next run; each failure was reported as it happened.
            $this->components->error("{$failures} item(s) could not be purged and remain in trash.");

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Force-delete every row the query matches, one chunk at a time.
     *
     * Loading the full result set was the previous behaviour and does not
     * survive a large trash: a purge that has to hold every trashed row in
     * memory hits the memory limit and deletes nothing. Deletion has to stay
     * row-by-row — Spatie removes the physical file from its MediaObserver, so
     * a mass delete() would orphan every object on disk.
     *
     * @param  Builder<covariant Model>  $query
     * @return array{0: int, 1: int} purged count, failed count
     */
    private function forceDeleteAll(Builder $query, int $chunk): array
    {
        $purged = 0;
        $failed = 0;

        $query->chunkById($chunk, function ($rows) use (&$purged, &$failed): void {
            foreach ($rows as $row) {
                try {
                    $row->forceDelete();
                    $purged++;
                } catch (Throwable $e) {
                    // One unreachable object must not abandon the rest of the
                    // sweep; the row stays in trash for the next run.
                    $failed++;
                    $this->components->warn(sprintf(
                        'Could not purge %s [%s]: %s',
                        $row::class,
                        (string) $row->getKey(),
                        $e->getMessage(),
                    ));
                }
            }
        });

        return [$purged, $failed];
    }

    /**
     * Take the run lock, or report that another run holds it.
     *
     * Returns null when the cache store cannot provide locks at all, in which
     * case the purge still runs. Losing overlap protection is worse than
     * nothing, but refusing to purge would be worse still for an install that
     * has always run without it.
     *
     * `Error|BadMethodCallException`, and both halves of that pair are needed.
     * `Cache::lock()` is not declared on `Repository` and reaches the store
     * through `__call`, so a store that does not implement it — Laravel's own
     * `session`, `storage` and `apc` stores among them — raises an `Error`,
     * which is not an `Exception`; catching only `BadMethodCallException`
     * would abort on a store that never supported locks in the first place.
     *
     * It is deliberately NOT `Throwable`. "This store has no locks" is a
     * static fact about the driver and is safe to run without. An unreachable
     * or misconfigured lock backend is an outage, and purging unprotected
     * through an outage is exactly the overlap this lock exists to stop — that
     * failure propagates and aborts the run.
     *
     * @return Lock|null|false the held lock, null when unsupported, false when another run holds it
     */
    private function acquireLock(): Lock|null|false
    {
        try {
            $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);
        } catch (Error|BadMethodCallException) {
            return null;
        }

        return $lock->get() ? $lock : false;
    }
}
