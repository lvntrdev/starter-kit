<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Package migration chain — real driver
|--------------------------------------------------------------------------
|
| Every assertion here runs AFTER MigrationTestCase::setUp() has executed the
| real `migrate` over stubs/database/migrations + database/migrations as one
| batch on MySQL/MariaDB. On SQLite the whole file skips (see MigrationTestCase).
|
| What this file is for: the kit's tables are built INLINE by DatabaseTestCase,
| so nothing else in the suite ever parses the migration DDL with a real engine.
| Index key-length limits, whether `uuid` compiles to a native type or char(36),
| and whether a FK's column type matches the column it references are all
| driver-specific and all invisible to the SQLite suite.
|
*/

/**
 * Look one index up by name.
 *
 * @return array{name: string, columns: list<string>, type: string, unique: bool, primary: bool}|null
 */
function migrationIndex(string $table, string $name): ?array
{
    foreach (Schema::getIndexes($table) as $index) {
        if (strtolower((string) $index['name']) === strtolower($name)) {
            return $index;
        }
    }

    return null;
}

/**
 * Look one foreign key up by name.
 *
 * @return array{name: string|null, columns: list<string>, foreign_table: string, foreign_columns: list<string>, on_update: string|null, on_delete: string|null}|null
 */
function migrationForeignKey(string $table, string $name): ?array
{
    foreach (Schema::getForeignKeys($table) as $foreignKey) {
        if (strtolower((string) $foreignKey['name']) === strtolower($name)) {
            return $foreignKey;
        }
    }

    return null;
}

it('applies the whole package migration chain on the target driver', function () {
    // Named in the plan: the kit-owned tables a consumer must end up with.
    $kitTables = [
        'settings',
        'file_folders',
        'file_favorites',
        'global_file_buckets',
        'file_manager_share_revocations',
    ];

    foreach ($kitTables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing kit table [{$table}]");
    }

    // The rest of the vendor chain, plus the app-owned prerequisites it needs.
    foreach (['media', 'activity_log', 'definitions', 'content_languages', 'users', 'roles'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing chain table [{$table}]");
    }

    // add_color_to_roles_table ALTERs an app-published table; if the stub and
    // the vendor migration ever drift on the `after('group')` anchor this is
    // where it surfaces, because MySQL rejects an unknown AFTER column.
    expect(Schema::hasColumn('roles', 'color'))->toBeTrue();

    // add_folder_id_to_media / add_soft_deletes_to_media both ALTER `media`.
    expect(Schema::hasColumn('media', 'folder_id'))->toBeTrue()
        ->and(Schema::hasColumn('media', 'deleted_at'))->toBeTrue();

    // Nothing ran twice: the package path is registered BOTH by
    // StarterKitServiceProvider::registerMigrations() and by the explicit
    // --path, and the migrator must dedupe by migration name.
    $ran = DB::table('migrations')->pluck('migration')->all();
    expect(count($ran))->toBe(count(array_unique($ran)))
        ->and(DB::table('migrations')->distinct()->count('batch'))->toBe(1);
});

it('creates every declared index on the target driver', function () {
    $expected = [
        // table => [index name => is unique]
        'settings' => [
            'settings_group_index' => false,
            'settings_group_key_unique' => true,
        ],
        'file_folders' => [
            'file_folders_parent_id_index' => false,
            'file_folders_created_by_index' => false,
            'file_folders_owner_type_owner_id_index' => false,
            'file_folders_owner_parent_name_unique' => true,
        ],
        'file_favorites' => [
            'file_favorites_owner_idx' => false,
            'file_favorites_unique' => true,
        ],
        'file_manager_share_revocations' => [
            'file_manager_share_revocations_tenant_id_index' => false,
            'fm_share_rev_media_token_unique' => true,
        ],
        'media' => [
            'media_folder_id_index' => false,
            'media_uuid_unique' => true,
        ],
    ];

    foreach ($expected as $table => $indexes) {
        foreach ($indexes as $name => $isUnique) {
            $index = migrationIndex($table, $name);

            expect($index)->not->toBeNull("missing index [{$name}] on [{$table}]");
            expect($index['unique'])->toBe($isUnique, "wrong uniqueness for [{$name}] on [{$table}]");
        }
    }

    // The composite uniques are the ones that can blow the InnoDB 3072-byte key
    // limit, so pin their column list rather than only their existence.
    expect(migrationIndex('file_folders', 'file_folders_owner_parent_name_unique')['columns'])
        ->toBe(['owner_type', 'owner_id', 'parent_id', 'name'])
        ->and(migrationIndex('file_favorites', 'file_favorites_unique')['columns'])
        ->toBe(['owner_type', 'owner_id', 'favoritable_type', 'favoritable_id'])
        // Security invariant: the revocation unique is COMPOSITE (media + token
        // hash), never a bare unique on the hash — a bare one would let one user
        // revoke another user's share token.
        ->and(migrationIndex('file_manager_share_revocations', 'fm_share_rev_media_token_unique')['columns'])
        ->toBe(['media_id', 'signed_token_hash']);

    // The tightest composite in the chain: three utf8mb4 varchar(255) columns
    // would be 3060 bytes of a 3072-byte InnoDB key, surviving only because
    // nobody widened one. `narrow_definitions_unique_index_columns` takes `lang`
    // to 35 and leaves `key`/`value` at their published 255, which is what the
    // width assertion below pins — the whole point of the migration is a real
    // driver having compiled that ALTER, and the name of an index proves
    // neither its uniqueness nor its column list.
    $definitionsUnique = migrationIndex('definitions', 'definitions_key_value_lang_unique');

    expect($definitionsUnique)->not->toBeNull()
        ->and($definitionsUnique['unique'])->toBeTrue()
        ->and($definitionsUnique['columns'])->toBe(['key', 'value', 'lang']);

    $langLength = DB::table('information_schema.columns')
        ->where('table_schema', DB::getDatabaseName())
        ->where('table_name', 'definitions')
        ->where('column_name', 'lang')
        ->value('CHARACTER_MAXIMUM_LENGTH');

    expect((int) $langLength)->toBe(35);
});

it('creates every declared foreign key with its declared referential action', function () {
    $expected = [
        // [table, constraint, referenced table, referenced column, on delete]
        ['file_folders', 'file_folders_parent_id_foreign', 'file_folders', 'id', 'cascade'],
        ['file_folders', 'file_folders_created_by_foreign', 'users', 'id', 'set null'],
        ['media', 'media_folder_id_foreign', 'file_folders', 'id', 'set null'],
        ['file_manager_share_revocations', 'file_manager_share_revocations_media_id_foreign', 'media', 'id', 'cascade'],
        ['file_manager_share_revocations', 'file_manager_share_revocations_revoked_by_user_id_foreign', 'users', 'id', 'set null'],
    ];

    foreach ($expected as [$table, $name, $foreignTable, $foreignColumn, $onDelete]) {
        $foreignKey = migrationForeignKey($table, $name);

        expect($foreignKey)->not->toBeNull("missing foreign key [{$name}] on [{$table}]");
        expect($foreignKey['foreign_table'])->toBe($foreignTable)
            ->and($foreignKey['foreign_columns'])->toBe([$foreignColumn])
            ->and(strtolower((string) $foreignKey['on_delete']))->toBe($onDelete, "wrong ON DELETE for [{$name}]");
    }
});

it('resolves every uuid column in the chain to one driver-native type', function () {
    // `uuid` is NOT one type across these drivers: MySQL compiles it to
    // char(36), MariaDB >= 10.7 to a native 16-byte `uuid`. A FK is only
    // creatable when both sides landed on the SAME compiled type, so the
    // invariant to assert is agreement — not a hard-coded type name.
    $uuidColumns = [
        ['users', 'id'],                                   // app-owned PK the kit FKs onto
        ['file_folders', 'id'],
        ['file_folders', 'parent_id'],
        ['file_folders', 'created_by'],
        ['file_folders', 'owner_id'],
        ['global_file_buckets', 'id'],
        ['file_favorites', 'id'],
        ['file_favorites', 'owner_id'],
        ['media', 'folder_id'],
        ['media', 'model_id'],
        ['file_manager_share_revocations', 'revoked_by_user_id'],
    ];

    $observed = [];

    foreach ($uuidColumns as [$table, $column]) {
        $observed["{$table}.{$column}"] = strtolower(Schema::getColumnType($table, $column, true));
    }

    expect(array_unique(array_values($observed)))
        ->toHaveCount(1, 'uuid columns compiled to more than one type: '.json_encode($observed));

    // Whatever the driver picked, it must be a type that can hold a 36-char
    // UUID string — the kit writes string UUIDs through Eloquent.
    $type = reset($observed);
    expect($type === 'uuid' || $type === 'char(36)')->toBeTrue("unexpected uuid column type [{$type}]");

    // The deliberate EXCEPTION, and the reason this whole job exists:
    // widen_activity_log_morphs_to_string converts the polymorphic
    // subject/causer ids OFF the uuid type to char(36), because those columns
    // must also hold Spatie's bigint role/permission keys. On MariaDB a native
    // uuid column rejects that write with error 4078.
    expect(strtolower(Schema::getColumnType('activity_log', 'subject_id', true)))->toBe('char(36)')
        ->and(strtolower(Schema::getColumnType('activity_log', 'causer_id', true)))->toBe('char(36)');
});

it('rolls the full migration batch back', function () {
    $this->rollbackMigrationChain();

    expect(DB::table('migrations')->count())->toBe(0);

    // Every kit table whose migration declares a down().
    $dropped = [
        'settings',
        'definitions',
        'content_languages',
        'activity_log',
        'global_file_buckets',
        'file_folders',
        'file_favorites',
        'file_manager_share_revocations',
        // Previously the KNOWN GAP of this file: create_media_table shipped
        // without a down(), so the migrator skipped it and `media` survived a
        // full rollback. It now declares one, so the batch unwinds completely.
        'media',
        // app-owned prerequisites, rolled back in the same batch
        'users',
        'roles',
    ];

    foreach ($dropped as $table) {
        expect(Schema::hasTable($table))->toBeFalse("rollback left [{$table}] behind");
    }

    // `media` is dropped LAST-in/first-out relative to the FKs pointing at it
    // (file_manager_share_revocations.media_id), which is the ordering the
    // batch rollback has to get right — a reverse-order failure surfaces here
    // as a constraint error rather than a leftover table.
    expect(Schema::hasTable('media'))->toBeFalse();
});
