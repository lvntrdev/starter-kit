<?php

namespace App\Http\Requests\FileManager;

use App\Domain\FileManager\DTOs\FileManagerContextDTO;
use App\Domain\FileManager\Support\ContextRegistry;
use App\Http\Requests\BaseFormRequest;
use Throwable;

/**
 * Shared base: every FileManager request resolves its context from query/body.
 *
 * Validation is driven by the runtime {@see ContextRegistry}. Any key it can
 * resolve — explicit registration, morph-map alias or `App\Models\{Studly}`
 * convention — is accepted without touching this class.
 */
abstract class FileManagerRequest extends BaseFormRequest
{
    protected string $attributeNamespace = 'sk-file-manager';

    public function authorize(): bool
    {
        return true;
    }

    public function context(): FileManagerContextDTO
    {
        return FileManagerContextDTO::fromArray([
            'context' => (string) $this->input('context', $this->query('context')),
            'context_id' => $this->input('context_id', $this->query('context_id')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function contextRules(): array
    {
        return [
            'context' => ['required', 'string', $this->contextKeyRule()],
            'context_id' => ['nullable', 'uuid', $this->contextIdRule()],
        ];
    }

    private function contextKeyRule(): callable
    {
        return function (string $attribute, mixed $value, callable $fail): void {
            /** @var ContextRegistry $registry */
            $registry = app(ContextRegistry::class);

            try {
                $registry->get((string) $value);
            } catch (Throwable $e) {
                $fail($e->getMessage());
            }
        };
    }

    private function contextIdRule(): callable
    {
        return function (string $attribute, mixed $value, callable $fail): void {
            $context = (string) $this->input('context', $this->query('context'));

            if ($context === '') {
                return;
            }

            /** @var ContextRegistry $registry */
            $registry = app(ContextRegistry::class);

            try {
                $definition = $registry->get($context);
            } catch (Throwable) {
                return; // context rule will surface the error
            }

            if ($definition->requiresId() && ($value === null || $value === '')) {
                $fail(__('validation.required', ['attribute' => $attribute]));
            }
        };
    }
}
