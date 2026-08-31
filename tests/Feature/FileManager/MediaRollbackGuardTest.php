<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| create_media_table::down() — drops an empty table, refuses a populated one
|--------------------------------------------------------------------------
|
| The migration shipped without a `down()` at all, and Laravel's migrator guards
| that call with `method_exists`: `migrate:rollback` skipped the table silently
| and deleted its ledger row anyway, so the table outlived its own record and
| the next `migrate` died on it.
|
| Adding an unconditional drop would have closed that hole by opening a worse
| one — this file is already published, consumers have already run it, and a
| rollback they have performed safely before would suddenly destroy their media
| index. So `down()` drops only what is empty. Both halves are pinned here,
| because either one silently flipping is a data-loss regression.
|
| The migration is loaded directly rather than run through the migrator: what is
| under test is the method's decision, not the DDL, and tests/Feature/Migration
| already exercises a real rollback against MySQL and MariaDB.
|
*/

function mrgMigration(string $basename = '2026_03_08_205445_create_media_table'): object
{
    $path = realpath(__DIR__.'/../../../database/migrations/'.$basename.'.php');

    expect($path)->not->toBeFalse();

    return require $path;
}

/**
 * The two migrations a rollback reaches BEFORE the create migration.
 *
 * A batch rolls back newest-first, so each of these runs while the create
 * migration's own refusal is still several steps away. Without a guard of their
 * own they would drop their column off a populated table and only then let
 * something else object — the folder assignment and the trash state would
 * already be gone.
 */
function mrgLaterMediaMigrations(): array
{
    return [
        'folder_id' => ['2026_04_13_100200_add_folder_id_to_media_table'],
        'deleted_at' => ['2026_05_02_094121_add_soft_deletes_to_media_table'],
    ];
}

/**
 * A minimal stand-in for the real table. DatabaseTestCase already builds a full
 * `media` inline, which is more than this decision needs and carries columns
 * that would make the insert below noisy — so it is replaced with the two
 * columns the assertions actually read.
 */
function mrgCreateMediaTable(): void
{
    Schema::dropIfExists('media');

    Schema::create('media', function (Blueprint $table) {
        $table->id();
        $table->string('file_name');
    });
}

it('drops the media table when there is nothing in it to lose', function (): void {
    mrgCreateMediaTable();

    mrgMigration()->down();

    expect(Schema::hasTable('media'))->toBeFalse();
});

it('refuses to roll back a media table that still holds rows', function (): void {
    mrgCreateMediaTable();
    DB::table('media')->insert(['file_name' => 'invoice.pdf']);

    expect(fn () => mrgMigration()->down())->toThrow(RuntimeException::class);

    // The refusal is only worth anything if it left the table standing.
    expect(Schema::hasTable('media'))->toBeTrue()
        ->and(DB::table('media')->count())->toBe(1);
});

it('is a no-op when the media table is already gone', function (): void {
    // spatie/laravel-medialibrary publishes its own create-media migration, so a
    // consumer app can reach this down() with the table dropped already.
    Schema::dropIfExists('media');

    expect(Schema::hasTable('media'))->toBeFalse();

    mrgMigration()->down();

    expect(Schema::hasTable('media'))->toBeFalse();
});

it('refuses to drop a later media column while the table still holds rows', function (string $basename): void {
    mrgCreateMediaTable();
    DB::table('media')->insert(['file_name' => 'invoice.pdf']);

    expect(fn () => mrgMigration($basename)->down())->toThrow(RuntimeException::class);

    expect(Schema::hasTable('media'))->toBeTrue()
        ->and(DB::table('media')->count())->toBe(1);
})->with(mrgLaterMediaMigrations());

it('lets a later media migration roll back once the table is empty', function (): void {
    // The column it drops is present here, so reaching the drop is what proves
    // the guard let go — not an early return past a missing column.
    Schema::dropIfExists('media');

    Schema::create('media', function (Blueprint $table) {
        $table->id();
        $table->string('file_name');
        $table->softDeletes();
    });

    mrgMigration('2026_05_02_094121_add_soft_deletes_to_media_table')->down();

    expect(Schema::hasTable('media'))->toBeTrue()
        ->and(Schema::hasColumn('media', 'deleted_at'))->toBeFalse();
});
