<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lvntr\StarterKit\Console\Commands\InstallCommand;

/**
 * The kit keys `users` on a uuid. A stock Laravel app that ran
 * `php artisan migrate` BEFORE installing the kit already owns a `users` table
 * with a bigint id — and has stock Laravel's users migration recorded in the
 * ledger under the SAME filename the kit publishes
 * (`0001_01_01_000000_create_users_table.php`), so the kit's uuid version of
 * that file never runs. The mismatch used to surface several migrations later
 * as a bare `SQLSTATE[HY000] ... 3780` on `file_folders.created_by`, with
 * nothing in it an operator could act on.
 *
 * This file pins the probe that names the cause up front, and the two places
 * its answer changes what the installer offers.
 *
 * Helpers carry a `usc` prefix: a Pest file declares its helpers at global
 * scope for the whole process, so bare names collide across files.
 */
function uscMethod(string $method): ReflectionMethod
{
    return new ReflectionMethod(InstallCommand::class, $method);
}

function uscConflict(): ?string
{
    return uscMethod('usersTableSchemaConflict')->invoke(new InstallCommand);
}

it('reports no conflict when the connection has no users table at all', function (): void {
    expect(uscConflict())->toBeNull();
});

it('reports a conflict for the stock Laravel integer-keyed users table', function (): void {
    Schema::create('users', function ($table): void {
        $table->id();
        $table->string('email');
    });

    expect(uscConflict())
        ->toContain('"users" table')
        ->toContain('uuid (char(36)) primary key');
});

it('reports no conflict for a uuid-keyed users table', function (): void {
    Schema::create('users', function ($table): void {
        $table->uuid('id')->primary();
        $table->string('email');
    });

    expect(uscConflict())->toBeNull();
});

it('fails open rather than blocking the install when the schema cannot be read', function (): void {
    // Unlike the row probe next door — which answers "may I destroy this?" and
    // must fail closed — this probe answers "will the additive path work?". An
    // unreachable connection must not invent a conflict and stop an install on
    // a question that was never answered.
    config(['database.connections.usc_dead' => [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 1,
        'database' => 'usc_dead',
        'username' => 'usc',
        'password' => 'usc',
    ]]);
    config(['database.default' => 'usc_dead']);
    DB::purge('usc_dead');

    expect(uscConflict())->toBeNull();
});

it('labels the additive option as doomed without ever making it non-default', function (): void {
    $options = uscMethod('migrationStrategyOptions')->invoke(new InstallCommand, true, true);

    // `migrate` stays FIRST and therefore stays the highlighted default: an
    // operator pressing Enter must never land on the destructive row, even on a
    // database where the additive path is known to fail.
    expect(array_key_first($options))->toBe('migrate')
        ->and($options['migrate'])->toContain('WILL FAIL')
        ->and($options)->toHaveKey('fresh');
});

it('leaves the additive option unlabelled on a compatible schema', function (): void {
    $options = uscMethod('migrationStrategyOptions')->invoke(new InstallCommand, true, false);

    expect($options['migrate'])->not->toContain('WILL FAIL');
});

it('names the reset as the only remedy in the step failure detail', function (): void {
    $detail = uscMethod('schemaConflictFailureDetail')->invoke(new InstallCommand, 'the id is bigint');

    expect($detail)
        ->toContain('the id is bigint')
        ->toContain('migrate:fresh');
});
