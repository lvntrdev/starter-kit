<?php

namespace App\Domain\Logs\Queries;

use App\Domain\Logs\DTOs\LogEntryDTO;
use App\Domain\Logs\DTOs\LogEntryFilterDTO;
use App\Domain\Logs\Services\LaravelLogParser;
use App\Exceptions\ApiException;

/**
 * Streams a single Laravel log file, parses entries, applies filters,
 * returns one page worth of LogEntryDTOs plus a byte-offset cursor.
 *
 * Cursor strategy: next_cursor is the file position where the entry
 * AFTER the last emitted one began. Re-issuing the request with that
 * cursor resumes scanning from the start of that entry.
 *
 * Memory safety: a single fopen() + line-by-line fgets() loop. Per-line
 * read is capped to PER_LINE_BYTE_CAP to defend against pathological
 * unbounded lines.
 *
 * Reads from storage_path('logs') directly because Laravel writes its
 * log files there at the OS level — outside any registered Flysystem
 * disk (the `local` disk roots at storage/app/, not storage/).
 */
class LogEntryQuery
{
    private const FILENAME_REGEX = '/^[A-Za-z0-9._-]+\.log$/';

    private const PER_LINE_BYTE_CAP = 65536; // 64KB

    public function __construct(
        private readonly LaravelLogParser $parser,
    ) {}

    /**
     * @return array{entries: list<array<string, mixed>>, next_cursor: ?int, eof: bool}
     */
    public function paginate(string $filename, LogEntryFilterDTO $filter): array
    {
        $absolutePath = $this->resolveAbsolutePath($filename);

        $handle = @fopen($absolutePath, 'rb');
        if ($handle === false) {
            throw ApiException::serverError(__('sk-log.read_failed'));
        }

        try {
            $startOffset = $filter->cursor ?? 0;
            if ($startOffset > 0) {
                fseek($handle, $startOffset);
            }

            /** @var list<LogEntryDTO> $entries */
            $entries = [];
            $currentHeader = null;
            $currentStack = [];
            /** @var list<string> $rawBuffer */
            $rawBuffer = [];

            while (! feof($handle)) {
                $linePos = ftell($handle);
                $line = fgets($handle, self::PER_LINE_BYTE_CAP);
                if ($line === false) {
                    break;
                }
                $line = rtrim($line, "\r\n");

                $header = $this->parser->parseLine($line);

                if ($header !== null) {
                    // Flush any pending raw block (lines that appeared before
                    // the first header) before starting the structured entry.
                    if (! empty($rawBuffer)) {
                        $rawEntry = $this->parser->buildRawEntry($rawBuffer);
                        $rawBuffer = [];
                        if ($this->matchesFilter($rawEntry, $filter)) {
                            $entries[] = $rawEntry;
                            if (count($entries) >= $filter->perPage) {
                                return [
                                    'entries' => array_map(fn (LogEntryDTO $e) => $e->toArray(), $entries),
                                    'next_cursor' => $linePos,
                                    'eof' => false,
                                ];
                            }
                        }
                    }

                    // Finalize the previous structured entry before starting
                    // the new one.
                    if ($currentHeader !== null) {
                        $entry = $this->parser->buildEntry($currentHeader, $currentStack);
                        if ($this->matchesFilter($entry, $filter)) {
                            $entries[] = $entry;
                            if (count($entries) >= $filter->perPage) {
                                return [
                                    'entries' => array_map(fn (LogEntryDTO $e) => $e->toArray(), $entries),
                                    'next_cursor' => $linePos,
                                    'eof' => false,
                                ];
                            }
                        }
                    }
                    $currentHeader = $header;
                    $currentStack = [];
                } else {
                    if ($currentHeader !== null) {
                        // Continuation of the current structured entry.
                        $currentStack[] = $line;
                    } else {
                        // Pre-header content — buffer so it surfaces as a raw
                        // entry instead of disappearing.
                        $rawBuffer[] = $line;
                    }
                }
            }

            // EOF — flush whatever is still buffered.
            if (! empty($rawBuffer)) {
                $rawEntry = $this->parser->buildRawEntry($rawBuffer);
                if ($this->matchesFilter($rawEntry, $filter)) {
                    $entries[] = $rawEntry;
                }
            }
            if ($currentHeader !== null) {
                $entry = $this->parser->buildEntry($currentHeader, $currentStack);
                if ($this->matchesFilter($entry, $filter)) {
                    $entries[] = $entry;
                }
            }

            return [
                'entries' => array_map(fn (LogEntryDTO $e) => $e->toArray(), $entries),
                'next_cursor' => null,
                'eof' => true,
            ];
        } finally {
            fclose($handle);
        }
    }

    private function resolveAbsolutePath(string $filename): string
    {
        if (! preg_match(self::FILENAME_REGEX, $filename)) {
            throw ApiException::badRequest(__('sk-log.invalid_filename'));
        }

        $absolutePath = storage_path('logs/'.$filename);

        if (! file_exists($absolutePath)) {
            throw ApiException::notFound(__('sk-log.file_not_found'));
        }

        return $absolutePath;
    }

    private function matchesFilter(LogEntryDTO $entry, LogEntryFilterDTO $filter): bool
    {
        // Raw entries only show when no structured filters are applied.
        if ($entry->isRaw) {
            return $filter->levels === null
                && $filter->from === null
                && $filter->to === null
                && ($filter->keyword === null || $filter->keyword === '');
        }

        if ($filter->levels !== null && ! in_array($entry->level, $filter->levels, true)) {
            return false;
        }

        if ($filter->from !== null && $entry->timestamp->lt($filter->from)) {
            return false;
        }

        if ($filter->to !== null && $entry->timestamp->gt($filter->to)) {
            return false;
        }

        if ($filter->keyword !== null && $filter->keyword !== '') {
            $haystack = $entry->message.' '.($entry->stack ?? '');
            if (stripos($haystack, $filter->keyword) === false) {
                return false;
            }
        }

        return true;
    }
}
