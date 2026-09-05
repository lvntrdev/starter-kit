<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lvntr\StarterKit\Console\Commands\InstallCommand;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The `migrate:fresh` branch of sk:install is the installer's one irreversible
 * operation: it drops every table on the configured connection. This file pins
 * the conditions under which it may be reached at all.
 *
 * What must stay true, in one sentence each:
 *
 *   1. A session with no human on it (--no-interaction, CI, no TTY) never sees
 *      the destructive option and always lands on additive `migrate`.
 *   2. `migrate` is the first entry AND the default of the choice list, so the
 *      one-Enter answer is never the destroying one.
 *   3. A production-LOOKING environment is more than a name: APP_ENV, APP_DEBUG
 *      and "the tables already hold rows" each withhold the option alone.
 *   4. The row probe fails CLOSED — a table it cannot read counts as full.
 *   5. Reaching `fresh` requires a TYPED confirmation; "y" does not authorise it.
 *   6. The genuine case survives: an interactive, debug, leftover-EMPTY-tables
 *      database still gets the option, so this is a narrowing, not a removal.
 *
 * Nothing here runs migrate:fresh. The assertions stop at the DECISION the
 * command reaches — chooseMigrationStrategy() returning 'fresh' is the last
 * observable step before runMigrations() would shell out to the artisan call.
 *
 * Helpers carry an `mfg` prefix: a Pest file declares its helpers at global
 * scope for the whole process, so bare names collide across files.
 */

/**
 * Test-only command that reports the guard's inputs and verdict as JSON.
 *
 * destructiveMigrationBlockReason() reads $this->option('no-interaction') via
 * canPrompt(), which needs a bound input; running through a real Artisan
 * command supplies that plus $this->components, exactly as sk:install would.
 */
final class MfgProbeRunner extends InstallCommand
{
    protected $signature = 'sk:test-fresh-guard {--tables=} {--typed=}';

    protected $description = 'Test-only: report the migrate:fresh guard decision.';

    public function handle(): int
    {
        $tables = array_values(array_filter(
            array_map('trim', explode(',', (string) $this->option('tables'))),
            static fn (string $table): bool => $table !== '',
        ));

        $reason = mfgMethod('destructiveMigrationBlockReason')->invoke($this, $tables);

        $this->output->writeln('SKGUARD'.json_encode([
            'canPrompt' => (bool) mfgMethod('canPrompt')->invoke($this),
            'reason' => $reason,
            'options' => array_keys(mfgMethod('migrationStrategyOptions')->invoke($this, $reason === null)),
            'typedAccepted' => (bool) mfgMethod('destructiveResetConfirmationMatches')
                ->invoke($this, (string) $this->option('typed')),
        ]));

        return self::SUCCESS;
    }
}

/**
 * Test-only command that drives the full interactive decision, so the choice
 * list and the typed confirmation are exercised through the real prompt wiring
 * rather than asserted piecemeal.
 */
final class MfgStrategyRunner extends InstallCommand
{
    protected $signature = 'sk:test-fresh-strategy {--tables=}';

    protected $description = 'Test-only: run InstallCommand::chooseMigrationStrategy().';

    public function handle(): int
    {
        $tables = array_values(array_filter(
            array_map('trim', explode(',', (string) $this->option('tables'))),
            static fn (string $table): bool => $table !== '',
        ));

        $this->output->writeln(
            'SKSTRATEGY:'.mfgMethod('chooseMigrationStrategy')->invoke($this, $tables),
        );

        return self::SUCCESS;
    }
}

/** Accessible handle on one of InstallCommand's private guard methods. */
function mfgMethod(string $name): ReflectionMethod
{
    $method = new ReflectionMethod(InstallCommand::class, $name);

    return $method;
}

/**
 * Run the probe command and decode its verdict.
 *
 * @param  array<string, mixed>  $parameters
 * @return array{canPrompt: bool, reason: string|null, options: list<string>, typedAccepted: bool}
 */
function mfgProbe(array $parameters = []): array
{
    app(Kernel::class)->registerCommand(new MfgProbeRunner);

    $buffer = new BufferedOutput;
    Artisan::call('sk:test-fresh-guard', $parameters, $buffer);

    $output = $buffer->fetch();
    $marker = strpos($output, 'SKGUARD');

    expect($marker)->not->toBeFalse("probe produced no verdict:\n{$output}");

    /** @var array{canPrompt: bool, reason: string|null, options: list<string>, typedAccepted: bool} $decoded */
    $decoded = json_decode(trim(substr($output, $marker + 7)), true, flags: JSON_THROW_ON_ERROR);

    return $decoded;
}

/** The database name the typed confirmation will accept on this connection. */
function mfgDatabaseName(): string
{
    return (string) config('database.connections.'.config('database.default').'.database');
}

/** Create a throwaway table, optionally with one row in it. */
function mfgSeedTable(string $name, bool $withRow = false): void
{
    Schema::create($name, function ($table): void {
        $table->increments('id');
    });

    if ($withRow) {
        DB::table($name)->insert(['id' => 1]);
    }
}

beforeEach(function (): void {
    // Testbench boots with APP_DEBUG off; the "may offer fresh" baseline is a
    // debug box, so each test starts there and opts INTO the blockers.
    //
    // The environment name is deliberately left at testbench's 'testing':
    // Application::runningUnitTests() is literally `env === 'testing'`, and
    // canPrompt() leans on it to simulate a TTY. Renaming the environment here
    // would silently turn every test into a "no human present" case.
    config(['app.debug' => true]);
});

// ── 1. No human on the session ────────────────────────────────────────────────

it('withholds the destructive option from a --no-interaction session', function (): void {
    $probe = mfgProbe(['--no-interaction' => true]);

    expect($probe['canPrompt'])->toBeFalse()
        ->and($probe['reason'])->toContain('cannot prompt')
        ->and($probe['options'])->toBe(['migrate', 'skip']);
});

it('withholds it under --no-interaction even on a pristine local debug box', function (): void {
    // The environment looks maximally safe — local, debug on, no tables. The
    // absence of a human is on its own enough to withhold the option.
    $probe = mfgProbe(['--no-interaction' => true, '--tables' => '']);

    expect($probe['reason'])->toContain('cannot prompt')
        ->and($probe['options'])->not->toContain('fresh');
});

it('answers migrate — never fresh, never skip — for a non-interactive session with tables', function (): void {
    mfgSeedTable('mfg_leftover');

    app(Kernel::class)->registerCommand(new MfgStrategyRunner);

    $buffer = new BufferedOutput;
    Artisan::call('sk:test-fresh-strategy', [
        '--tables' => 'mfg_leftover',
        '--no-interaction' => true,
    ], $buffer);

    expect($buffer->fetch())->toContain('SKSTRATEGY:migrate');
});

// ── 2. Ordering and default of the choice list ────────────────────────────────

it('lists migrate first whether or not fresh is offered', function (): void {
    expect(mfgProbe()['options'])->toBe(['migrate', 'fresh', 'skip'])
        ->and(mfgProbe(['--no-interaction' => true])['options'])->toBe(['migrate', 'skip']);
});

// ── 3. Production-LIKE is wider than the environment name ─────────────────────

it('withholds the option when APP_ENV looks like production', function (): void {
    app()->detectEnvironment(fn (): string => 'prod-eu');

    $probe = mfgProbe();

    expect($probe['reason'])->toContain('APP_ENV is "prod-eu"')
        ->and($probe['options'])->toBe(['migrate', 'skip']);
});

it('withholds the option when APP_DEBUG is off, whatever the environment is called', function (): void {
    config(['app.debug' => false]);

    $probe = mfgProbe();

    expect($probe['reason'])->toContain('APP_DEBUG is off')
        ->and($probe['options'])->toBe(['migrate', 'skip']);
});

it('withholds the option when an existing table already holds rows', function (): void {
    mfgSeedTable('mfg_populated', withRow: true);

    $probe = mfgProbe(['--tables' => 'mfg_populated']);

    expect($probe['reason'])->toContain('"mfg_populated" already holds rows')
        ->and($probe['options'])->toBe(['migrate', 'skip']);
});

it('names the populated table even when empty tables are inspected first', function (): void {
    mfgSeedTable('mfg_empty_a');
    mfgSeedTable('mfg_populated_b', withRow: true);

    expect(mfgProbe(['--tables' => 'mfg_empty_a,mfg_populated_b'])['reason'])
        ->toContain('"mfg_populated_b" already holds rows');
});

// ── 4. The row probe fails closed ─────────────────────────────────────────────

it('treats a table it cannot read as holding live data instead of throwing', function (): void {
    // Stands in for the permission-limited / dropped-mid-probe database: the
    // query throws, and the guard must answer "blocked", not propagate.
    $probe = mfgProbe(['--tables' => 'mfg_no_such_table']);

    expect($probe['reason'])->toContain('could not be read')
        ->and($probe['reason'])->toContain('treated as holding live data')
        ->and($probe['options'])->toBe(['migrate', 'skip']);
});

it('answers an empty table list rather than throwing when the schema cannot be read', function (): void {
    // No connection at all: existingApplicationTables() must not push a first
    // install down the "existing tables" branch on an unreachable database.
    config(['database.connections.mfg_dead' => [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 1,
        'database' => 'mfg_dead',
        'username' => 'mfg',
        'password' => 'mfg',
    ]]);
    config(['database.default' => 'mfg_dead']);
    DB::purge('mfg_dead');

    $command = new InstallCommand;

    expect(mfgMethod('existingApplicationTables')->invoke($command))->toBe([]);
});

// ── 5. The typed confirmation ─────────────────────────────────────────────────

it('rejects every reflex answer to the typed confirmation', function (string $typed): void {
    expect(mfgProbe(['--typed' => $typed])['typedAccepted'])->toBeFalse();
})->with(['', ' ', 'y', 'Y', 'yes', 'YES', 'no', 'confirm', 'drop', 'wrong-database']);

it('accepts the literal word fresh, case-insensitively and whitespace-trimmed', function (string $typed): void {
    expect(mfgProbe(['--typed' => $typed])['typedAccepted'])->toBeTrue();
})->with(['fresh', 'FRESH', 'Fresh', '  fresh  ']);

it('accepts the exact database name of the current connection', function (): void {
    expect(mfgProbe(['--typed' => mfgDatabaseName()])['typedAccepted'])->toBeTrue()
        ->and(mfgProbe(['--typed' => mfgDatabaseName().'x'])['typedAccepted'])->toBeFalse();
});

it('refuses a typed answer when the connection carries no database name', function (): void {
    config(['database.connections.'.config('database.default').'.database' => null]);

    // Only the literal remains; an empty answer must not match an empty name.
    expect(mfgProbe(['--typed' => ''])['typedAccepted'])->toBeFalse()
        ->and(mfgProbe(['--typed' => 'fresh'])['typedAccepted'])->toBeTrue();
});

// ── 5b. The confirmation actually gates the decision ──────────────────────────

it('falls back to the additive migrate when the operator picks fresh but mistypes the confirmation', function (): void {
    // Not 'skip': skipping runs NO migrations at all, so the install walks on
    // into seeders against a schema that was never built. The docs and the
    // changelog both promise the additive path here.
    mfgSeedTable('mfg_leftover');
    app(Kernel::class)->registerCommand(new MfgStrategyRunner);

    $this->artisan('sk:test-fresh-strategy', ['--tables' => 'mfg_leftover'])
        ->expectsChoice('How would you like to proceed?', 'fresh', [
            'migrate' => 'Run pending migrations only (keep existing data)',
            'fresh' => 'Drop all tables and run fresh migrations (ALL DATA WILL BE LOST)',
            'skip' => 'Skip migrations',
        ])
        ->expectsQuestion(
            sprintf('Type the database name (%s) or the word "fresh" to confirm', mfgDatabaseName()),
            'y',
        )
        ->expectsOutputToContain('SKSTRATEGY:migrate')
        ->assertExitCode(0);
});

it('reaches fresh only after the confirmation is typed correctly', function (): void {
    mfgSeedTable('mfg_leftover');
    app(Kernel::class)->registerCommand(new MfgStrategyRunner);

    $this->artisan('sk:test-fresh-strategy', ['--tables' => 'mfg_leftover'])
        ->expectsChoice('How would you like to proceed?', 'fresh', [
            'migrate' => 'Run pending migrations only (keep existing data)',
            'fresh' => 'Drop all tables and run fresh migrations (ALL DATA WILL BE LOST)',
            'skip' => 'Skip migrations',
        ])
        ->expectsQuestion(
            sprintf('Type the database name (%s) or the word "fresh" to confirm', mfgDatabaseName()),
            'fresh',
        )
        ->expectsOutputToContain('SKSTRATEGY:fresh')
        ->assertExitCode(0);
});

// ── 6. The genuine case survives ──────────────────────────────────────────────

it('still offers the option for an interactive install onto leftover EMPTY tables', function (): void {
    mfgSeedTable('mfg_leftover_a');
    mfgSeedTable('mfg_leftover_b');

    $probe = mfgProbe(['--tables' => 'mfg_leftover_a,mfg_leftover_b']);

    expect($probe['reason'])->toBeNull()
        ->and($probe['options'])->toBe(['migrate', 'fresh', 'skip']);
});

it('does not count the migrations ledger as existing tables or as live data', function (): void {
    // A half-run install always leaves rows in `migrations`. Counting them would
    // withhold the option from every database the branch exists to serve.
    mfgSeedTable('migrations', withRow: true);

    $command = new InstallCommand;

    expect(mfgMethod('existingApplicationTables')->invoke($command))->not->toContain('migrations');
});

it('reports leftover application tables alongside the ledger', function (): void {
    mfgSeedTable('migrations', withRow: true);
    mfgSeedTable('mfg_leftover');

    $tables = mfgMethod('existingApplicationTables')->invoke(new InstallCommand);

    expect($tables)->toContain('mfg_leftover')
        ->and($tables)->not->toContain('migrations');
});
