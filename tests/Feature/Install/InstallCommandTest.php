<?php

use Illuminate\Console\OutputStyle;
use Lvntr\StarterKit\Console\Commands\InstallCommand;
use Lvntr\StarterKit\Console\Support\RecipeRegistry;
use Symfony\Component\Console\Input\ArrayInput;

require_once __DIR__.'/InstallDecisionHarness.php';

/**
 * `sk:install --modules=` (Task 2's optional observability recipes) × the
 * decisions that must never reach a real `composer require` in a test run.
 *
 * InstallCommand shells out to `composer`/`php artisan <recipe>` via Symfony's
 * `Process` directly, not the `Illuminate\Support\Facades\Process` facade — so
 * `Process::fake()` fakes nothing here. What IS testable without a network call
 * is every seam installRecipes() is built from: option parsing, the unknown-key
 * guard, the two command builders, the interactive-prompt short-circuit, and
 * the dry-run summary, which reads recipeKeysFromOption() but never calls
 * installRecipes() at all (see InstallCommand::renderDryRunPlan — no Process
 * construction on that path, confirmed by reading the method).
 */

/**
 * An InstallCommand wired with a specific --modules= input, optionally forced
 * non-interactive the way Symfony's Application does for --no-interaction.
 *
 * @return array{0: InstallCommand, 1: OutputStyle|null}
 */
function icrCommand(array $parameters = [], bool $interactive = true): array
{
    [$command, $output] = iciCommand();

    $input = new ArrayInput($parameters, $command->getDefinition());
    $input->setInteractive($interactive);

    (new ReflectionProperty($command, 'input'))->setValue($command, $input);

    return [$command, $output];
}

it('normalizes --modules= from both repeated flags and comma-separated values', function () {
    [$command] = icrCommand(['--modules' => ['telescope', 'pulse,sentry', ' Pulse ']]);

    expect(iciInvoke($command, 'recipeKeysFromOption'))
        ->toBe(['telescope', 'pulse', 'sentry']);
});

it('returns no recipe keys when --modules= was never given', function () {
    [$command] = icrCommand();

    expect(iciInvoke($command, 'recipeKeysFromOption'))->toBe([]);
});

it('reports --modules= values that name no known recipe', function () {
    [$command] = icrCommand(['--modules' => ['telescope', 'bogus']]);

    expect(iciInvoke($command, 'unknownRecipeKeys'))->toBe(['bogus']);
});

it('reports no unknown keys when every --modules= value is a real recipe', function () {
    [$command] = icrCommand(['--modules' => ['telescope', 'pulse', 'sentry']]);

    expect(iciInvoke($command, 'unknownRecipeKeys'))->toBe([]);
});

it('prefers the --modules= selection over prompting', function () {
    [$command] = icrCommand(['--modules' => ['pulse']]);

    expect(iciInvoke($command, 'selectedRecipes'))->toBe(['pulse']);
});

it('skips the interactive prompt entirely under --no-interaction', function () {
    [$command] = icrCommand(interactive: false);

    expect(iciInvoke($command, 'promptForRecipes'))->toBe([]);
});

it('builds the composer require command for a dev-only recipe with --dev appended', function () {
    [$command] = icrCommand();

    // Synthetic: no shipped recipe is dev-only any more, but the flag branch stays.
    $recipe = ['composer' => 'vendor/dev-tool', 'dev' => true, 'label' => 'Dev tool', 'post_install' => []];

    expect(iciInvoke($command, 'recipeRequireCommand', [['composer'], $recipe]))
        ->toBe(['composer', 'require', '--dev', 'vendor/dev-tool', '--no-interaction']);
});

it('builds the composer require command for a production recipe without --dev', function () {
    [$command] = icrCommand();

    $recipe = RecipeRegistry::get('pulse');

    expect(iciInvoke($command, 'recipeRequireCommand', [['composer'], $recipe]))
        ->toBe(['composer', 'require', 'laravel/pulse', '--no-interaction']);
});

it('builds the post-install artisan command as a child php process with --no-interaction', function () {
    $dir = iciBoot();
    [$command] = icrCommand();

    expect(iciInvoke($command, 'recipeArtisanCommand', ['telescope:install']))
        ->toBe([PHP_BINARY, $dir.'/artisan', 'telescope:install', '--no-interaction']);

    iciRemove($dir);
});

it('shows the dry-run plan for --modules= without ever running a recipe command', function () {
    [$command, $output] = icrCommand(['--modules' => ['telescope']]);

    iciInvoke($command, 'renderDryRunPlan');

    expect($output->fetch())
        ->toContain('Dry run')
        ->toContain('Would install optional modules')
        ->toContain('telescope');
});

it('shows no optional-module line in the dry-run plan when --modules= was not given', function () {
    [$command, $output] = icrCommand();

    iciInvoke($command, 'renderDryRunPlan');

    expect($output->fetch())->not->toContain('Would install optional modules');
});
