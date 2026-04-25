<?php

namespace App\Domain\Logs\Queries;

use App\Domain\Logs\DTOs\LogFileDTO;
use App\Exceptions\ApiException;
use App\Http\Responses\ApiResponse;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use SplFileInfo;

/**
 * Lists files in storage/logs/ as LogFileDTOs and serves them to the
 * SkDatatable on the Log Files index page.
 *
 * Reads directly from storage_path('logs') because Laravel writes its log
 * files there at the OS level — they are NOT routed through the
 * `local` filesystem disk (which points at storage/app/).
 *
 * Backend filter / sort / pagination is implemented in-memory because the
 * file list is bounded (a handful per channel + daily rotation) and we
 * cannot push the work down to a SQL engine.
 */
class LogFileQuery
{
    private const FILENAME_REGEX = '/^[A-Za-z0-9._-]+\.log$/';

    private const ALLOWED_SORT_KEYS = ['name', 'size_bytes', 'modified_at', 'channel_type', 'is_active'];

    private const ALLOWED_SORT_DIRS = ['asc', 'desc'];

    public function response(): ApiResponse
    {
        $request = request();

        $items = $this->all()->map(fn (LogFileDTO $dto) => $dto->toArray());

        // Search (filename contains)
        $search = (string) $request->query('search', '');
        if ($search !== '') {
            $needle = strtolower($search);
            $items = $items->filter(fn (array $row) => str_contains(strtolower($row['name']), $needle))->values();
        }

        // Sort
        $rawSortKey = (string) $request->query('sort', 'modified_at');
        $rawSortDir = strtolower((string) $request->query('direction', 'desc'));
        $sortKey = in_array($rawSortKey, self::ALLOWED_SORT_KEYS, true) ? $rawSortKey : 'modified_at';
        $sortDir = in_array($rawSortDir, self::ALLOWED_SORT_DIRS, true) ? $rawSortDir : 'desc';
        $items = $items->sortBy(
            fn (array $row) => $row[$sortKey] ?? null,
            SORT_REGULAR,
            $sortDir === 'desc',
        )->values();

        // Paginate (per_page capped at 100)
        $perPage = max(1, min((int) $request->query('per_page', 25), 100));
        $page = max(1, (int) $request->query('page', 1));
        $total = $items->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $sliced = $items->slice(($page - 1) * $perPage, $perPage)->values();
        $from = $total === 0 ? null : ($page - 1) * $perPage + 1;
        $to = $total === 0 ? null : min($page * $perPage, $total);

        // Shape matches Laravel\\Pagination output consumed by SkDatatable
        // (data + total + per_page + current_page + last_page + from + to)
        return ApiResponse::success([
            'data' => $sliced->all(),
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => $lastPage,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * Return all log files as DTOs.
     *
     * @return Collection<int, LogFileDTO>
     */
    public function all(): Collection
    {
        $logsPath = storage_path('logs');

        if (! File::isDirectory($logsPath)) {
            return collect();
        }

        return collect(File::files($logsPath))
            ->filter(fn (SplFileInfo $info) => preg_match(self::FILENAME_REGEX, $info->getFilename()) === 1)
            ->map(function (SplFileInfo $info): LogFileDTO {
                $name = $info->getFilename();
                $absolute = $info->getRealPath() ?: $info->getPathname();

                return new LogFileDTO(
                    name: $name,
                    path: $absolute,
                    sizeBytes: (int) $info->getSize(),
                    modifiedAt: Carbon::createFromTimestamp($info->getMTime()),
                    channelType: $this->detectChannelType($name),
                    isActive: $this->isActive($name, $absolute),
                );
            })
            ->values();
    }

    /**
     * Find a single file by name.
     *
     * @throws ApiException if filename is invalid or missing.
     */
    public function find(string $filename): LogFileDTO
    {
        if (! preg_match(self::FILENAME_REGEX, $filename)) {
            throw ApiException::badRequest(__('sk-log.invalid_filename'));
        }

        $dto = $this->all()->firstWhere('name', $filename);

        if ($dto === null) {
            throw ApiException::notFound(__('sk-log.file_not_found'));
        }

        return $dto;
    }

    private function detectChannelType(string $name): string
    {
        if (preg_match('/^laravel-\d{4}-\d{2}-\d{2}\.log$/', $name)) {
            return 'daily';
        }
        if ($name === 'laravel.log') {
            return 'single';
        }

        return 'other';
    }

    private function isActive(string $name, string $absolutePath): bool
    {
        // Today's daily file is always active.
        if ($name === 'laravel-'.now()->toDateString().'.log') {
            return true;
        }

        // Files written within the last 5 seconds are presumed actively appended.
        $mtime = @filemtime($absolutePath);
        if ($mtime !== false && (time() - $mtime) <= 5) {
            return true;
        }

        return false;
    }
}
