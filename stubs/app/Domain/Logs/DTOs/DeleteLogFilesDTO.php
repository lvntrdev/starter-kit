<?php

namespace App\Domain\Logs\DTOs;

use App\Domain\Shared\DTOs\BaseDTO;

/**
 * Bulk delete payload — list of filenames already validated for path safety
 * by DeleteLogFilesRequest.
 */
readonly class DeleteLogFilesDTO extends BaseDTO
{
    /**
     * @param  list<string>  $filenames
     */
    public function __construct(
        public array $filenames,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        return new static(
            filenames: array_values($data['filenames'] ?? []),
        );
    }
}
