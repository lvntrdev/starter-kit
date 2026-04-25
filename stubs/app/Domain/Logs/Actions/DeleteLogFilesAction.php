<?php

namespace App\Domain\Logs\Actions;

use App\Domain\Logs\DTOs\DeleteLogFilesDTO;
use App\Domain\Logs\Events\LogFilesDeleted;
use App\Domain\Logs\Queries\LogFileQuery;
use App\Domain\Shared\Actions\BaseAction;
use Illuminate\Support\Facades\File;

/**
 * Bulk delete files in storage/logs/.
 *
 * Per-file behaviour:
 *   - Validates filename matches the safe regex (defence in depth — the
 *     FormRequest also enforces it)
 *   - Rejects files marked as active by LogFileQuery (today's daily
 *     file or mtime within last 5 seconds)
 *   - Returns a structured success / failure breakdown so the caller can
 *     surface partial results to the UI
 *
 * Dispatches LogFilesDeleted with the names that were actually removed.
 */
class DeleteLogFilesAction extends BaseAction
{
    private const FILENAME_REGEX = '/^[A-Za-z0-9._-]+\.log$/';

    public function __construct(
        private readonly LogFileQuery $files,
    ) {}

    /**
     * @return array{deleted: list<string>, failed: list<array{filename: string, reason: string}>}
     */
    public function execute(DeleteLogFilesDTO $dto, int|string|null $performedById = null): array
    {
        $allFiles = $this->files->all()->keyBy('name');

        $deleted = [];
        $failed = [];

        foreach (array_unique($dto->filenames) as $filename) {
            if (! preg_match(self::FILENAME_REGEX, $filename)) {
                $failed[] = ['filename' => $filename, 'reason' => 'invalid_filename'];

                continue;
            }

            $file = $allFiles->get($filename);
            if ($file === null) {
                $failed[] = ['filename' => $filename, 'reason' => 'not_found'];

                continue;
            }

            if ($file->isActive) {
                $failed[] = ['filename' => $filename, 'reason' => 'active_file_protected'];

                continue;
            }

            $absolutePath = storage_path('logs/'.$filename);
            try {
                if (File::delete($absolutePath)) {
                    $deleted[] = $filename;
                } else {
                    $failed[] = ['filename' => $filename, 'reason' => 'delete_failed'];
                }
            } catch (\Throwable $e) {
                $failed[] = ['filename' => $filename, 'reason' => 'delete_failed'];
            }
        }

        if (! empty($deleted)) {
            LogFilesDeleted::dispatch($deleted, $performedById);
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }
}
