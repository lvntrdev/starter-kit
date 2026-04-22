<?php

namespace App\Domain\Setting\DTOs;

use App\Domain\Shared\DTOs\BaseDTO;

/**
 * Data Transfer Object for Apidog integration settings.
 *
 * `access_token` follows the same rule as Turnstile's secret — a blank
 * submission means "keep the currently stored value".
 */
readonly class ApidogSettingsDTO extends BaseDTO
{
    public function __construct(
        public ?string $accessToken,
        public ?string $projectId,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        return new static(
            accessToken: $data['access_token'] ?? null,
            projectId: $data['project_id'] ?? null,
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        $data = [
            'project_id' => $this->projectId,
        ];

        if ($this->accessToken !== null && $this->accessToken !== '') {
            $data['access_token'] = $this->accessToken;
        }

        return $data;
    }
}
