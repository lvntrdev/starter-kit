<?php

namespace App\Support\Translation;

use Illuminate\Contracts\Translation\Loader;

/**
 * Loader decorator that merges `sk-attribute.php` into Laravel's
 * `validation` translation group on load, so that standard keys like
 * `validation.attributes.first_name` and `validation.custom.{attr}.{rule}`
 * resolve to the values declared in `lang/{locale}/sk-attribute.php`.
 *
 * Published/user-defined validation.php keys still take precedence for
 * rule messages; attribute/custom sections are overlaid from sk-attribute.
 */
class SkAttributeTranslationLoader implements Loader
{
    public function __construct(private readonly Loader $inner) {}

    public function load($locale, $group, $namespace = null): array
    {
        $lines = $this->inner->load($locale, $group, $namespace);

        if (($namespace === null || $namespace === '*') && $group === 'validation') {
            $sk = $this->inner->load($locale, 'sk-attribute');

            if (isset($sk['attributes']) && is_array($sk['attributes'])) {
                $lines['attributes'] = array_merge(
                    $lines['attributes'] ?? [],
                    $sk['attributes']
                );
            }

            if (isset($sk['custom']) && is_array($sk['custom'])) {
                $lines['custom'] = array_merge(
                    $lines['custom'] ?? [],
                    $sk['custom']
                );
            }
        }

        return $lines;
    }

    public function addNamespace($namespace, $hint): void
    {
        $this->inner->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path): void
    {
        $this->inner->addJsonPath($path);
    }

    public function namespaces(): array
    {
        return $this->inner->namespaces();
    }
}
