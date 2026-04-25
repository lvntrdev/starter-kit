<?php

namespace App\Domain\Logs\Listeners;

use App\Domain\Logs\Events\LogFilesDeleted;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Records a single activitylog entry per delete operation.
 * Runs on queue to avoid blocking the request.
 *
 * `tries = 1` because activity() has no dedup guard — a queue retry would
 * insert a duplicate row. Activity log loss on a transient failure is
 * preferable to silent duplication of audit records.
 */
class LogActivityForLogFilesDeleted implements ShouldQueue
{
    public int $tries = 1;

    /**
     * Handle the event.
     */
    public function handle(LogFilesDeleted $event): void
    {
        if (empty($event->deletedFilenames)) {
            return;
        }

        activity('system')
            ->causedBy($event->performedBy)
            ->withProperties(['filenames' => $event->deletedFilenames])
            ->log('Deleted '.count($event->deletedFilenames).' log file(s)');
    }
}
