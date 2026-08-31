<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| 2026_08_31_120000_narrow_definitions_unique_index_columns — regression
|--------------------------------------------------------------------------
|
| Pins the two behaviours the migration promises: narrows cleanly against
| clean data, and refuses (leaving the schema untouched) against dirty data.
| Runs the real migration file's up()/down() directly against the SQLite
| in-memory connection (DatabaseTestCase) rather than through the real-driver
| MigrationTestCase chain — SQLite reports column widths loosely, so length
| itself is never asserted; only the observable behaviour (refusal message,
| index presence, no truncation of the stored value) is.
|
*/

const NARROWING_MIGRATION_PATH = __DIR__.'/../../../database/migrations/2026_08_31_120000_narrow_definitions_unique_index_columns.php';

const NARROWING_UNIQUE_INDEX = 'definitions_key_value_lang_unique';

function loadNarrowingMigration(): Migration
{
    return require NARROWING_MIGRATION_PATH;
}

function createOriginalDefinitionsTable(): void
{
    Schema::create('definitions', function (Blueprint $table): void {
        $table->id();
        $table->string('key')->index();
        $table->string('value');
        $table->string('label');
        $table->text('explanation')->nullable();
        $table->string('severity')->nullable();
        $table->string('icon')->nullable();
        $table->boolean('is_active')->default(true);
        $table->integer('order')->default(0);
        $table->boolean('visibility')->default(true);
        $table->string('lang')->default('en');
        $table->timestamps();
        $table->softDeletes();

        $table->unique(['key', 'value', 'lang'], NARROWING_UNIQUE_INDEX);
    });
}

function definitionsHasUniqueIndex(): bool
{
    foreach (Schema::getIndexes('definitions') as $index) {
        if (strtolower((string) $index['name']) === NARROWING_UNIQUE_INDEX) {
            return $index['unique'] === true;
        }
    }

    return false;
}

afterEach(function (): void {
    Schema::dropIfExists('definitions');
});

it('narrows lang cleanly when every row already fits', function (): void {
    createOriginalDefinitionsTable();

    DB::table('definitions')->insert([
        'key' => 'short-key',
        'value' => 'short-value',
        'label' => 'Short label',
        'lang' => 'en',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    loadNarrowingMigration()->up();

    expect(definitionsHasUniqueIndex())->toBeTrue()
        ->and(DB::table('definitions')->where('key', 'short-key')->exists())->toBeTrue();
});

it('leaves key at its published width — a long key is not an upgrade blocker', function (): void {
    // An earlier draft also narrowed `key` to 191 and would have refused here.
    // With `lang` at 35 the index already clears InnoDB's ceiling by ~892
    // bytes, so a 200-character key — which the published 255-character column
    // accepts today — must migrate untouched and stay writable afterwards.
    createOriginalDefinitionsTable();

    $longKey = str_repeat('k', 200);

    DB::table('definitions')->insert([
        'key' => $longKey,
        'value' => 'value',
        'label' => 'Label',
        'lang' => 'en',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    loadNarrowingMigration()->up();

    expect(definitionsHasUniqueIndex())->toBeTrue()
        ->and(DB::table('definitions')->where('key', $longKey)->value('key'))->toBe($longKey);

    DB::table('definitions')->insert([
        'key' => str_repeat('n', 250),
        'value' => 'value',
        'label' => 'Label',
        'lang' => 'en',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('definitions')->where('key', str_repeat('n', 250))->exists())->toBeTrue();
});

it('restores a unique index that was already missing before it ran', function (): void {
    // The regression this pins: the in-place path trusted the driver to carry
    // the index through the ALTER and returned as soon as the column change
    // succeeded. A table that arrived here WITHOUT the index — a half-finished
    // earlier run, or a consumer who dropped it by hand — was therefore
    // recorded as migrated with no unique constraint at all, and duplicate
    // (key, value, lang) rows stayed insertable.
    createOriginalDefinitionsTable();

    Schema::table('definitions', function (Blueprint $table): void {
        $table->dropUnique(NARROWING_UNIQUE_INDEX);
    });

    expect(definitionsHasUniqueIndex())->toBeFalse();

    loadNarrowingMigration()->up();

    expect(definitionsHasUniqueIndex())->toBeTrue();

    $row = [
        'key' => 'dup-key',
        'value' => 'dup-value',
        'label' => 'Label',
        'lang' => 'en',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('definitions')->insert($row);

    expect(fn () => DB::table('definitions')->insert($row))
        ->toThrow(QueryException::class);

    expect(DB::table('definitions')->where('key', 'dup-key')->count())->toBe(1);
});

it('replaces a same-named index that does not carry the guarantee', function (): void {
    // Schema drift: an index by the kit's name is there, so a name-only check
    // reports "present" and returns — but it is a PLAIN index, so duplicates
    // stay insertable while the migration records itself as done.
    createOriginalDefinitionsTable();

    Schema::table('definitions', function (Blueprint $table): void {
        $table->dropUnique(NARROWING_UNIQUE_INDEX);
        $table->index(['key', 'value', 'lang'], NARROWING_UNIQUE_INDEX);
    });

    expect(definitionsHasUniqueIndex())->toBeFalse();

    loadNarrowingMigration()->up();

    expect(definitionsHasUniqueIndex())->toBeTrue();

    $row = [
        'key' => 'drift-key',
        'value' => 'drift-value',
        'label' => 'Label',
        'lang' => 'en',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('definitions')->insert($row);

    expect(fn () => DB::table('definitions')->insert($row))
        ->toThrow(QueryException::class);
});

it('replaces a same-named unique index built over the wrong columns', function (): void {
    // Same name, genuinely unique — but only over (key, lang). A row that
    // differs in `value` alone is rejected by it, and the guarantee the kit
    // declares is not the one enforced.
    createOriginalDefinitionsTable();

    Schema::table('definitions', function (Blueprint $table): void {
        $table->dropUnique(NARROWING_UNIQUE_INDEX);
        $table->unique(['key', 'lang'], NARROWING_UNIQUE_INDEX);
    });

    loadNarrowingMigration()->up();

    expect(definitionsHasUniqueIndex())->toBeTrue();

    // The kit's guarantee: differing in `value` alone is allowed.
    foreach (['first', 'second'] as $value) {
        DB::table('definitions')->insert([
            'key' => 'same-key',
            'value' => $value,
            'label' => 'Label',
            'lang' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    expect(DB::table('definitions')->where('key', 'same-key')->count())->toBe(2);
});

it('leaves an equivalent index alone when only the column order differs', function (): void {
    // Uniqueness over a SET is the same guarantee whatever order the index
    // lists it in, so rebuilding it would drop the constraint for the length of
    // the rebuild and buy nothing.
    createOriginalDefinitionsTable();

    Schema::table('definitions', function (Blueprint $table): void {
        $table->dropUnique(NARROWING_UNIQUE_INDEX);
        $table->unique(['lang', 'value', 'key'], NARROWING_UNIQUE_INDEX);
    });

    loadNarrowingMigration()->up();

    $index = collect(Schema::getIndexes('definitions'))
        ->firstWhere(fn (array $index): bool => strtolower((string) $index['name']) === NARROWING_UNIQUE_INDEX);

    expect($index)->not->toBeNull()
        ->and($index['unique'])->toBeTrue()
        ->and(array_map('strtolower', $index['columns']))->toBe(['lang', 'value', 'key']);
});

it('refuses to narrow when a lang exceeds the new limit, changing nothing', function (): void {
    createOriginalDefinitionsTable();

    $longLang = str_repeat('l', 50);

    DB::table('definitions')->insert([
        'key' => 'key',
        'value' => 'value',
        'label' => 'Label',
        'lang' => $longLang,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => loadNarrowingMigration()->up())
        ->toThrow(RuntimeException::class, 'Refusing to narrow');

    expect(definitionsHasUniqueIndex())->toBeTrue()
        ->and(DB::table('definitions')->where('lang', $longLang)->value('lang'))->toBe($longLang);
});

it('accepts the widest locale value the rest of the kit allows', function (): void {
    // `lang` is capped at the same 35 characters `content_languages.code`
    // validates to, so a consumer who mirrored a long content-language code
    // into `definitions.lang` upgrades instead of hitting the refusal. This is
    // the boundary: exactly 35 must pass.
    createOriginalDefinitionsTable();

    $widestAllowed = str_repeat('l', 35);

    DB::table('definitions')->insert([
        'key' => 'key',
        'value' => 'value',
        'label' => 'Label',
        'lang' => $widestAllowed,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    loadNarrowingMigration()->up();

    expect(definitionsHasUniqueIndex())->toBeTrue()
        ->and(DB::table('definitions')->where('lang', $widestAllowed)->value('lang'))->toBe($widestAllowed);
});

it('widens the columns back and recreates the index on down()', function (): void {
    createOriginalDefinitionsTable();

    DB::table('definitions')->insert([
        'key' => 'short-key',
        'value' => 'short-value',
        'label' => 'Short label',
        'lang' => 'en',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration = loadNarrowingMigration();
    $migration->up();
    $migration->down();

    expect(definitionsHasUniqueIndex())->toBeTrue();

    // A value that would have been refused by the narrowed limits must be
    // storable again once down() has widened the columns back to 255.
    $wideLang = str_repeat('l', 50);

    DB::table('definitions')->insert([
        'key' => str_repeat('k', 200),
        'value' => 'value-2',
        'label' => 'Label',
        'lang' => $wideLang,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('definitions')->where('lang', $wideLang)->exists())->toBeTrue();
});

it('is a no-op when the definitions table does not exist', function (): void {
    Schema::dropIfExists('definitions');

    expect(Schema::hasTable('definitions'))->toBeFalse();

    loadNarrowingMigration()->up();

    expect(Schema::hasTable('definitions'))->toBeFalse();
});
