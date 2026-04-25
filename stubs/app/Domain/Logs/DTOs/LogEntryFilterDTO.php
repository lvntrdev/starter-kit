<?php

namespace App\Domain\Logs\DTOs;

use App\Domain\Shared\DTOs\BaseDTO;
use Carbon\Carbon;

/**
 * Filter / pagination payload for LogEntryQuery.
 * `cursor` is the byte offset returned by the previous page's `next_cursor`.
 */
readonly class LogEntryFilterDTO extends BaseDTO
{
    /**
     * @param  list<string>|null  $levels
     */
    public function __construct(
        public ?array $levels,
        public ?Carbon $from,
        public ?Carbon $to,
        public ?string $keyword,
        public ?int $cursor,
        public int $perPage,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $levels = $data['levels'] ?? null;
        if (is_array($levels) && count($levels) === 0) {
            $levels = null;
        }

        return new static(
            levels: $levels !== null ? array_values(array_map('strtolower', $levels)) : null,
            from: ! empty($data['from']) ? Carbon::parse($data['from']) : null,
            to: ! empty($data['to']) ? Carbon::parse($data['to']) : null,
            keyword: ! empty($data['keyword']) ? (string) $data['keyword'] : null,
            cursor: isset($data['cursor']) && $data['cursor'] !== '' ? (int) $data['cursor'] : null,
            perPage: (int) ($data['per_page'] ?? 100),
        );
    }
}
