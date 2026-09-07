<?php

/*
|--------------------------------------------------------------------------
| RecipeRegistry — static observability recipe catalog
|--------------------------------------------------------------------------
|
| Pure data class: no composer/artisan command may run as a side effect of
| reading it. Each recipe declares exactly what InstallCommand (Task 2) needs
| to run `composer require` and any post-install artisan step in order.
|
*/

use Lvntr\StarterKit\Console\Support\RecipeRegistry;

it('returns every recipe keyed by its identifier', function () {
    $recipes = RecipeRegistry::all();

    expect($recipes)->toHaveKeys(['telescope', 'pulse', 'horizon', 'sentry']);
});

it('defines the telescope recipe with a dev-only composer package and install step', function () {
    $recipe = RecipeRegistry::get('telescope');

    expect($recipe['composer'])->toBe('laravel/telescope')
        ->and($recipe['dev'])->toBeTrue()
        ->and($recipe['post_install'])->toBe(['telescope:install']);
});

it('defines the pulse recipe as a production dependency with no post-install step', function () {
    $recipe = RecipeRegistry::get('pulse');

    expect($recipe['composer'])->toBe('laravel/pulse')
        ->and($recipe['dev'])->toBeFalse()
        ->and($recipe['post_install'])->toBe([]);
});

it('defines the horizon recipe as a production dependency with its install step', function () {
    $recipe = RecipeRegistry::get('horizon');

    expect($recipe['composer'])->toBe('laravel/horizon')
        ->and($recipe['dev'])->toBeFalse()
        ->and($recipe['post_install'])->toBe(['horizon:install']);
});

it('defines the sentry recipe with its config publish step', function () {
    $recipe = RecipeRegistry::get('sentry');

    expect($recipe['composer'])->toBe('sentry/sentry-laravel')
        ->and($recipe['dev'])->toBeFalse()
        ->and($recipe['post_install'])->toBe(['vendor:publish --tag=sentry-config']);
});

it('throws for an unknown recipe key', function () {
    RecipeRegistry::get('does-not-exist');
})->throws(InvalidArgumentException::class);

it('exposes a label per recipe for the multiselect prompt', function () {
    $labels = RecipeRegistry::labels();

    expect($labels)->toHaveKeys(['telescope', 'pulse', 'horizon', 'sentry'])
        ->and($labels['telescope'])->toBeString()->not->toBeEmpty();
});
