<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Console\Support;

use InvalidArgumentException;
use Lvntr\StarterKit\Console\Commands\PublishCommand;

/**
 * Static catalog of optional observability/monitoring recipes offered by
 * `sk:install` (and, later, `sk:publish`/`sk:doctor`). Mirrors the shape of
 * {@see PublishCommand::PUBLISHABLE_TAGS}:
 * a single source-of-truth array read by whichever command needs it.
 *
 * This class is intentionally side-effect-free — it only returns data. Running
 * `composer require` / `artisan` for a selected recipe is the caller's job
 * (`InstallCommand`), not this registry's.
 */
class RecipeRegistry
{
    /**
     * @var array<string, array{composer: string, dev: bool, label: string, post_install: list<string>}>
     */
    private const RECIPES = [
        'telescope' => [
            'composer' => 'laravel/telescope',
            'dev' => true,
            'label' => 'Laravel Telescope (local debugging/request inspector)',
            'post_install' => ['telescope:install'],
        ],
        'pulse' => [
            'composer' => 'laravel/pulse',
            'dev' => false,
            'label' => 'Laravel Pulse (application performance monitoring)',
            'post_install' => [],
        ],
        'sentry' => [
            'composer' => 'sentry/sentry-laravel',
            'dev' => false,
            'label' => 'Sentry (error tracking)',
            'post_install' => ['vendor:publish --tag=sentry-config'],
        ],
    ];

    /**
     * @return array<string, array{composer: string, dev: bool, label: string, post_install: list<string>}>
     */
    public static function all(): array
    {
        return self::RECIPES;
    }

    /**
     * @return array{composer: string, dev: bool, label: string, post_install: list<string>}
     */
    public static function get(string $key): array
    {
        if (! isset(self::RECIPES[$key])) {
            throw new InvalidArgumentException("Unknown observability recipe: {$key}");
        }

        return self::RECIPES[$key];
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return array_map(fn (array $recipe): string => $recipe['label'], self::RECIPES);
    }
}
