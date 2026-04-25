<?php

namespace App\Domain\Logs\DTOs;

use App\Domain\Shared\DTOs\BaseDTO;
use Carbon\Carbon;

/**
 * One parsed log entry. Multiline stack traces are joined into `stack`.
 * `isRaw` marks lines the parser couldn't interpret as a Laravel-format entry.
 */
readonly class LogEntryDTO extends BaseDTO
{
    /**
     * @param  array<string, mixed>|null  $context
     */
    public function __construct(
        public Carbon $timestamp,
        public string $level,   // 'error', 'warning', 'info', 'debug', 'unknown', ...
        public string $env,     // 'local', 'production', ...
        public string $message,
        public ?array $context,
        public ?string $stack,
        public bool $isRaw,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        return new static(
            timestamp: $data['timestamp'] instanceof Carbon ? $data['timestamp'] : Carbon::parse($data['timestamp']),
            level: $data['level'],
            env: $data['env'],
            message: $data['message'],
            context: $data['context'] ?? null,
            stack: $data['stack'] ?? null,
            isRaw: (bool) ($data['is_raw'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'timestamp' => $this->timestamp->toIso8601String(),
            'level' => $this->level,
            'env' => $this->env,
            'message' => $this->message,
            'context' => $this->context,
            'stack' => $this->stack,
            'is_raw' => $this->isRaw,
        ];
    }
}
