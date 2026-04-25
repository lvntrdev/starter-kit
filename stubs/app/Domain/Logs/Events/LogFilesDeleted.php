<?php

namespace App\Domain\Logs\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched after a successful (partial-or-full) bulk log file delete.
 * Carries the names of files that were actually removed plus the user
 * who triggered the delete.
 */
class LogFilesDeleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<string>  $deletedFilenames
     */
    public function __construct(
        public readonly array $deletedFilenames,
        public readonly int|string|null $performedBy = null,
    ) {}
}
