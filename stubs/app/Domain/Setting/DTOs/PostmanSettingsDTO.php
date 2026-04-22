<?php

namespace App\Domain\Setting\DTOs;

use App\Domain\Shared\DTOs\BaseDTO;

/**
 * Data Transfer Object for Postman integration settings.
 *
 * `api_key` is treated like Turnstile's secret — a blank submission means
 * "keep the currently stored value".
 */
readonly class PostmanSettingsDTO extends BaseDTO
{
    public function __construct(
        public ?string $apiKey,
        public ?string $workspaceId,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        return new static(
            apiKey: $data['api_key'] ?? null,
            workspaceId: $data['workspace_id'] ?? null,
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        $data = [
            'workspace_id' => $this->workspaceId,
        ];

        // Omit api_key when blank so the existing stored secret is preserved.
        if ($this->apiKey !== null && $this->apiKey !== '') {
            $data['api_key'] = $this->apiKey;
        }

        return $data;
    }
}
