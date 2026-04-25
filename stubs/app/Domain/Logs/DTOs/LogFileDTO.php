<?php

namespace App\Domain\Logs\DTOs;

use App\Domain\Shared\DTOs\BaseDTO;
use Carbon\Carbon;

/**
 * Metadata for a single file in storage/logs/.
 * Built by LogFileQuery; consumed by datatable + delete validation.
 */
readonly class LogFileDTO extends BaseDTO
{
    public function __construct(
        public string $name,
        public string $path,
        public int $sizeBytes,
        public Carbon $modifiedAt,
        public string $channelType, // 'daily' | 'single' | 'other'
        public bool $isActive,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        return new static(
            name: $data['name'],
            path: $data['path'],
            sizeBytes: (int) $data['size_bytes'],
            modifiedAt: $data['modified_at'] instanceof Carbon ? $data['modified_at'] : Carbon::parse($data['modified_at']),
            channelType: $data['channel_type'],
            isActive: (bool) $data['is_active'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'path' => $this->path,
            'size_bytes' => $this->sizeBytes,
            'modified_at' => $this->modifiedAt->toIso8601String(),
            'channel_type' => $this->channelType,
            'is_active' => $this->isActive,
        ];
    }
}
