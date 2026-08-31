<?php

use Lvntr\StarterKit\Console\Commands\InstallCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputOption;

require_once __DIR__.'/InstallDecisionHarness.php';

/**
 * `chooseMigrationStrategy()` is the only path to `migrate:fresh` — a real
 * DROP-ALL-TABLES operation — anywhere in sk:install. Driving it end to end
 * would mean feeding laravel/prompts' select()/text() a fake terminal, which
 * this project's laravel/prompts version (^0.3.0, no Prompt::fake()) cannot
 * do outside a real TTY. So, exactly like IncompleteInstallTest does for
 * handle(), this file exercises the decision methods directly: the
 * no-interaction gate and the block-reason guard fully decide whether the
 * destructive option is ever reachable, and destructiveResetConfirmationMatches()
 * is itself the "was the typed confirmation correct" decision — no test here
 * ever calls migrate:fresh or select()/text().
 *
 * The iciBoot/iciCommand/iciInvoke helpers come from InstallDecisionHarness.php,
 * required above. They used to be borrowed from IncompleteInstallTest.php's
 * global scope, which held only while both files were collected in the same
 * run — `pest <this file>` alone died on an undefined function.
 */

/**
 * The harness's ArrayInput is built from a bare InstallCommand definition,
 * which — outside a real `artisan` run — never gained Console's global
 * --no-interaction option. canPrompt() reads it via $this->option(), which
 * throws on an undeclared option, so every case here binds it explicitly.
 */
function msWithNoInteraction(InstallCommand $command, bool $noInteraction): void
{
    $definition = $command->getDefinition();

    if (! $definition->hasOption('no-interaction')) {
        $definition->addOption(new InputOption('no-interaction', null, InputOption::VALUE_NONE));
    }

    $command->setInput(new ArrayInput($noInteraction ? ['--no-interaction' => true] : [], $definition));
}

beforeEach(function (): void {
    $GLOBALS['ici_trees'] = [];
    config(['app.debug' => true]);
});

afterEach(function (): void {
    foreach ($GLOBALS['ici_trees'] ?? [] as $dir) {
        iciRemove($dir);
    }

    $GLOBALS['ici_trees'] = [];
});

it('never selects the destructive branch under --no-interaction, whatever the environment name is', function (): void {
    iciBoot();
    [$command] = iciCommand();
    msWithNoInteraction($command, true);

    // An environment name that reads like a green light for the destructive
    // option — the no-interaction gate must still win regardless.
    app()->instance('env', 'fresh-drop-all-tables');

    $action = iciInvoke($command, 'chooseMigrationStrategy', [['users']]);

    expect($action)->toBe('migrate');
});

it('withholds the destructive option in a production-like environment and states why', function (): void {
    iciBoot();
    [$command] = iciCommand();
    app()->instance('env', 'production');

    $reason = iciInvoke($command, 'destructiveMigrationBlockReason', [[]]);

    expect($reason)->toBe('APP_ENV is "production"');
});

it('offers the destructive option in a non-production environment, but rejects a wrong or empty typed confirmation', function (): void {
    iciBoot();
    [$command] = iciCommand();
    msWithNoInteraction($command, false);
    $command->setLaravel(app());

    // Default test environment is APP_ENV=testing (phpunit.xml) — not
    // production-like — with app.debug forced on above and no existing
    // tables to probe, so nothing withholds the option.
    $reason = iciInvoke($command, 'destructiveMigrationBlockReason', [[]]);
    expect($reason)->toBeNull();

    // The gate a wrong/empty typed answer must fail: destructiveResetConfirmationMatches()
    // is what confirmDestructiveReset() returns, and chooseMigrationStrategy()
    // maps a false here to the additive 'migrate' — never 'fresh', and never
    // 'skip' either (skipping would walk the install into seeders with no schema).
    expect(iciInvoke($command, 'destructiveResetConfirmationMatches', ['']))->toBeFalse()
        ->and(iciInvoke($command, 'destructiveResetConfirmationMatches', ['yes']))->toBeFalse()
        ->and(iciInvoke($command, 'destructiveResetConfirmationMatches', ['y']))->toBeFalse();
});

it('accepts the correct typed confirmation as authorising the destructive branch', function (): void {
    iciBoot();
    [$command] = iciCommand();

    // The literal word always authorises, whatever the database name is.
    expect(iciInvoke($command, 'destructiveResetConfirmationMatches', ['fresh']))->toBeTrue()
        ->and(iciInvoke($command, 'destructiveResetConfirmationMatches', ['FRESH']))->toBeTrue()
        ->and(iciInvoke($command, 'destructiveResetConfirmationMatches', ['  fresh  ']))->toBeTrue();

    // The database name is the other accepted answer — surrounding whitespace
    // forgiven (a pasted value carries it), nothing else.
    $database = iciInvoke($command, 'currentDatabaseName');

    if ($database !== '') {
        expect(iciInvoke($command, 'destructiveResetConfirmationMatches', [$database]))->toBeTrue();
    }
});
