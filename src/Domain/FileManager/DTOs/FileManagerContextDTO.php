<?php

namespace Lvntr\StarterKit\Domain\FileManager\DTOs;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Lvntr\StarterKit\Domain\FileManager\Support\ContextRegistry;
use Lvntr\StarterKit\Domain\Shared\DTOs\BaseDTO;

/**
 * Resolved FileManager context — carries the owner model and ownership identity
 * used to scope folder trees, media queries, and PathGenerator output.
 */
readonly class FileManagerContextDTO extends BaseDTO
{
    public function __construct(
        public string $context,
        public ?string $contextId,
        public Model $owner,
        public string $ownerType,
        public string $ownerId,
    ) {}

    /**
     * The shape is deliberately permissive: this factory is fed straight from
     * request input, so every field is validated in the body rather than
     * assumed. Declaring the keys as required/non-null would contradict the
     * guards below.
     *
     * @param  array{context?: string|null, context_id?: string|null}  $data
     */
    public static function fromArray(array $data): static
    {
        $context = $data['context'] ?? null;
        $contextId = $data['context_id'] ?? null;

        if (! is_string($context) || $context === '') {
            throw new InvalidArgumentException('FileManager context key is required.');
        }

        /** @var ContextRegistry $registry */
        $registry = app(ContextRegistry::class);
        $definition = $registry->get($context);

        if ($definition->requiresId() && ! is_string($contextId)) {
            throw new InvalidArgumentException("FileManager context '{$context}' requires a context_id.");
        }

        $owner = $definition->resolveOwner($contextId);

        return new self(
            context: $context,
            contextId: $contextId,
            owner: $owner,
            // Matches what Spatie MediaLibrary (and any morph-aware query)
            // stores as `model_type`: the morph alias when the model is in
            // Laravel's morph map, otherwise the fully-qualified class name.
            ownerType: $owner->getMorphClass(),
            ownerId: (string) $owner->getKey(),
        );
    }

    public static function forUser(string $userId): self
    {
        return self::fromArray(['context' => 'user', 'context_id' => $userId]);
    }

    public static function forGlobal(): self
    {
        return self::fromArray(['context' => 'global']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'context' => $this->context,
            'context_id' => $this->contextId,
            'owner_type' => $this->ownerType,
            'owner_id' => $this->ownerId,
        ];
    }
}
