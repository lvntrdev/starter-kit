<?php

namespace App\Domain\Setting\DTOs;

use App\Domain\Shared\DTOs\BaseDTO;

/**
 * Data Transfer Object for turnstile settings.
 */
readonly class TurnstileSettingsDTO extends BaseDTO
{
    public function __construct(
        public string $enabled,
        public ?string $siteKey,
        public ?string $secretKey,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        return new static(
            enabled: $data['enabled'] ? '1' : '0',
            siteKey: $data['site_key'] ?? null,
            secretKey: $data['secret_key'] ?? null,
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'site_key' => $this->siteKey,
            'secret_key' => $this->secretKey,
        ];
    }
}
