<?php

namespace Lvntr\StarterKit\Http\Requests\FileManager;

use Illuminate\Foundation\Http\FormRequest;
use Lvntr\StarterKit\Domain\FileManager\DTOs\FileManagerContextDTO;
use Lvntr\StarterKit\Domain\FileManager\Support\ContextRegistry;
use Throwable;

/**
 * Shared base: every FileManager request resolves its context from query/body.
 *
 * Validation is driven by the runtime {@see ContextRegistry}. Any key it can
 * resolve — explicit registration, morph-map alias or `App\Models\{Studly}`
 * convention — is accepted without touching this class.
 */
abstract class FileManagerRequest extends FormRequest
{
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

    /**
     * True when any dot segment after the base name is on the media library's
     * disallowed-extension list — the same per-segment check the media
     * library runs when a file is added, applied here so a rename (which
     * never passes through FileAdder) or an older media-library build cannot
     * store `shell.php.jpg`. Case-insensitive.
     */
    protected function hasDisallowedExtensionSegment(string $fileName): bool
    {
        $segments = array_map(trim(...), array_slice(explode('.', strtolower($fileName)), 1));
        $blocked = array_map(strtolower(...), (array) config('media-library.disallowed_extensions', []));

        return array_intersect($segments, $blocked) !== [];
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
