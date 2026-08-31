<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Narrow `definitions.lang` so the composite unique index stops sitting 12
 * bytes under the InnoDB limit.
 *
 * ## The margin this closes
 *
 * `create_definitions_table` declares `unique(['key','value','lang'])` over three
 * default `string()` columns. On utf8mb4 that is 3 x 255 x 4 = 3060 bytes of the
 * 3072-byte InnoDB key limit: the index exists only because nobody has ever
 * widened one of those columns by a single character. After this migration the
 * key is (255 + 255 + 35) x 4 = 2180 bytes, leaving ~892 bytes of headroom.
 *
 * ## Why only `lang`, and why 35
 *
 * `lang` alone is enough. It is a locale tag, not free text, and 35 is not a
 * guess either — it is the WIDEST locale value the kit already accepts anywhere
 * (`content_languages.code` validates to 35 in
 * `Http/Requests/Admin/ContentLanguage/StoreContentLanguageRequest`), so
 * anything storable through the kit's own screens still fits and the refusal
 * below stays unreachable for it. A tighter 12 would fit every BCP-47 tag this
 * kit can seed (`zh-Hant-TW` is 11) and would still have stopped the upgrade of
 * a consumer who had mirrored a longer content-language code into
 * `definitions.lang` — a refusal, never a truncation, but a refusal in the
 * middle of their upgrade.
 *
 * `key` and `value` are deliberately LEFT AT 255. Narrowing `key` to Laravel's
 * index-safe 191 was in an earlier draft of this migration and is dropped: with
 * `lang` at 35 the index already clears the InnoDB ceiling by ~892 bytes, so the
 * narrowing bought headroom nobody needs while turning a 200-character key —
 * data the published 255-character column accepts today — into a hard stop on a
 * consumer's upgrade. Backward compatibility outranks headroom that is already
 * sufficient.
 *
 * ## Fail-closed: measure first, change second
 *
 * Narrowing a column can truncate data. `up()` therefore measures the existing
 * rows BEFORE issuing any DDL and throws — having changed nothing — if a single
 * row would not fit. A table that cannot be read is treated as a stop, never as
 * a green light. Soft-deleted rows are measured too: `deleted_at` does not
 * exclude a row from the unique index, so it must not exclude it from the probe.
 *
 * ## Lock / rollback
 *
 * One `ALTER TABLE` plus an index rebuild on `definitions`, a small reference
 * table (the kit seeds ~34 rows); the operation is sub-second at that size. On a
 * consumer table grown to millions of rows the ALTER holds a metadata lock for
 * the rebuild, so schedule it with the same care as any other ALTER.
 * `down()` restores the column to 255 and leaves the same index in place — a
 * widening, which can never truncate and so needs no probe.
 */
return new class extends Migration
{
    private const TABLE = 'definitions';

    private const UNIQUE_INDEX = 'definitions_key_value_lang_unique';

    /** @var list<string> */
    private const UNIQUE_COLUMNS = ['key', 'value', 'lang'];

    private const LANG_LIMIT = 35;

    private const ORIGINAL_LIMIT = 255;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Fresh-install ordering: this file may sort ahead of the table on a
        // consumer that publishes the chain out of order. Nothing to narrow.
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $this->guardAgainstTruncation('lang', self::LANG_LIMIT);

        $this->resize(self::LANG_LIMIT);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $this->resize(self::ORIGINAL_LIMIT);
    }

    /**
     * Restate `lang` at the given width, keeping the unique index up for as long
     * as the driver allows.
     *
     * The obvious shape — drop the index, alter, re-create it — opens a window
     * that MySQL and MariaDB cannot close: DDL there auto-commits, so between
     * the drop and the re-create the application is free to insert a duplicate
     * `(key, value, lang)`. The re-create then fails, the migration is never
     * recorded, and the table is left with no unique constraint at all; every
     * retry fails the same way until someone cleans the duplicates by hand. The
     * table would be without its guarantee precisely while it is being used.
     *
     * So the alter is attempted with the index STILL IN PLACE first, which both
     * MySQL and MariaDB accept for a plain width change (the index is rebuilt
     * as part of the ALTER, atomically from the application's point of view).
     * Only if a driver refuses that — SQLite rebuilds the whole table for a
     * column change — is the index dropped, and then it is restored on the way
     * out whether the alter succeeded or threw.
     *
     * Either way the method's POSTCONDITION is the same and is asserted, not
     * assumed: when this returns, `(key, value, lang)` uniqueness is ENFORCED —
     * an index by that name is not enough, it has to be unique and over those
     * columns. The in-place path used to trust the driver to carry the index
     * through the ALTER, so a table that arrived here without the guarantee — a
     * half-finished earlier run, a consumer who dropped or replaced the index by
     * hand — was recorded as migrated with nothing enforcing it, and duplicate
     * `(key, value, lang)` rows became insertable.
     */
    private function resize(int $langLimit): void
    {
        $changed = false;

        try {
            $this->changeColumns($langLimit);
            $changed = true;
        } catch (Throwable) {
            // The driver refuses a column change while the index is up; fall
            // through to the drop / change / re-create path below.
        }

        if ($changed) {
            $this->createUniqueIndexIfMissing();

            return;
        }

        $this->dropIndexNamed();

        try {
            $this->changeColumns($langLimit);
        } finally {
            $this->createUniqueIndex();
        }
    }

    /**
     * `change()` restates the WHOLE column definition — every modifier that is
     * not repeated is dropped, which is why `lang` re-declares its default.
     */
    private function changeColumns(int $langLimit): void
    {
        Schema::table(self::TABLE, function (Blueprint $table) use ($langLimit) {
            $table->string('lang', $langLimit)->default('en')->change();
        });
    }

    /**
     * Refuse the narrowing if any existing row would lose characters.
     *
     * Throws before a single DDL statement is issued, so a refusal leaves the
     * schema exactly as it was found.
     */
    private function guardAgainstTruncation(string $column, int $limit): void
    {
        $connection = Schema::getConnection();
        $length = $this->lengthExpression($column);

        try {
            $row = $connection->table(self::TABLE)
                ->selectRaw("MAX({$length}) as longest")
                ->selectRaw("SUM(CASE WHEN {$length} > ? THEN 1 ELSE 0 END) as over_limit", [$limit])
                ->first();
        } catch (Throwable $exception) {
            throw new RuntimeException(sprintf(
                'Refusing to narrow [%s.%s] to %d characters: the existing rows could not be measured (%s). '
                .'Nothing has been changed.',
                self::TABLE,
                $column,
                $limit,
                $exception->getMessage(),
            ), previous: $exception);
        }

        if ($row === null) {
            throw new RuntimeException(sprintf(
                'Refusing to narrow [%s.%s] to %d characters: the length probe returned no result, '
                .'so the table could not be proven safe. Nothing has been changed.',
                self::TABLE,
                $column,
                $limit,
            ));
        }

        $longest = (int) ($row->longest ?? 0);
        $overLimit = (int) ($row->over_limit ?? 0);

        if ($overLimit === 0) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to narrow [%s.%s] from %d to %d characters: %d row(s) exceed the new limit and the '
            .'longest is %d characters. Shorten or delete those rows (soft-deleted rows count — they still '
            .'occupy the unique index), then re-run this migration. Nothing has been changed.',
            self::TABLE,
            $column,
            self::ORIGINAL_LIMIT,
            $limit,
            $overLimit,
            $longest,
        ));
    }

    /**
     * A CHARACTER-length expression for the driver in play.
     *
     * `LENGTH()` is bytes on MySQL/MariaDB/PostgreSQL — measuring "Yasaklı" as
     * 8 there — and characters on SQLite, which has no `CHAR_LENGTH()`. The
     * column name goes through the query grammar because `key` is a reserved
     * word and an unwrapped one is a syntax error.
     */
    private function lengthExpression(string $column): string
    {
        $connection = Schema::getConnection();
        $wrapped = $connection->getQueryGrammar()->wrap($column);

        return match ($connection->getDriverName()) {
            'sqlite' => "LENGTH({$wrapped})",
            'sqlsrv' => "LEN({$wrapped})",
            default => "CHAR_LENGTH({$wrapped})",
        };
    }

    /**
     * Drop whatever currently carries the index NAME, whatever shape it is in.
     *
     * Keyed off the name and not off the guarantee on purpose: the name is what
     * `createUniqueIndex()` needs freed, so a same-named index that is NOT the
     * kit's unique — a plain index, or a unique over a different column set —
     * has to go here too, or the create fails with "index already exists". The
     * drop statement follows the shape found: `dropUnique()` for a unique
     * (which on PostgreSQL is a CONSTRAINT and cannot be dropped as an index),
     * `dropIndex()` for a plain one.
     *
     * Tolerating an already-absent index keeps a re-run after a partial failure
     * from dying on the very first statement. A DUPLICATE-KEY failure while
     * re-creating it is NOT tolerated — that one must surface.
     */
    private function dropIndexNamed(): void
    {
        $index = $this->indexNamed();

        if ($index === null) {
            return;
        }

        $isUnique = ($index['unique'] ?? false) === true;

        Schema::table(self::TABLE, function (Blueprint $table) use ($isUnique) {
            $isUnique
                ? $table->dropUnique(self::UNIQUE_INDEX)
                : $table->dropIndex(self::UNIQUE_INDEX);
        });
    }

    /**
     * Restore the guarantee the in-place ALTER was expected to carry through.
     *
     * A no-op on the ordinary run. It earns its place on the table that arrived
     * here without the constraint: rebuilding it there is what turns "the
     * migration ran" into "the unique guarantee holds". A duplicate-key failure
     * surfaces — a table with duplicates must not be recorded as migrated.
     */
    private function createUniqueIndexIfMissing(): void
    {
        if ($this->uniqueGuaranteeHolds()) {
            return;
        }

        // Something is sitting on the name without carrying the guarantee.
        // Clear it first, or the create below fails on the name collision.
        $this->dropIndexNamed();

        $this->createUniqueIndex();
    }

    private function createUniqueIndex(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->unique(self::UNIQUE_COLUMNS, self::UNIQUE_INDEX);
        });
    }

    /**
     * Is the (key, value, lang) uniqueness actually enforced right now?
     *
     * The NAME alone proves nothing. A schema-drifted table can carry an index
     * called `definitions_key_value_lang_unique` that is a plain index, or a
     * unique over a narrower column set — either way duplicates are insertable
     * while the name suggests otherwise, and returning true here would let the
     * migration record itself as done over a table with no guarantee at all.
     *
     * Column ORDER is not part of the comparison: uniqueness over a set of
     * columns is the same guarantee whichever order the index lists them in,
     * and rebuilding an equivalent index would drop the constraint for the
     * length of the rebuild for nothing. A different SET is a different
     * guarantee and does not pass.
     */
    private function uniqueGuaranteeHolds(): bool
    {
        $index = $this->indexNamed();

        if ($index === null || ($index['unique'] ?? false) !== true) {
            return false;
        }

        $columns = array_map(strtolower(...), (array) ($index['columns'] ?? []));
        $expected = self::UNIQUE_COLUMNS;

        sort($columns);
        sort($expected);

        return $columns === $expected;
    }

    /**
     * The index carrying the kit's name, in whatever shape, or null.
     *
     * @return array<string, mixed>|null
     */
    private function indexNamed(): ?array
    {
        foreach (Schema::getIndexes(self::TABLE) as $index) {
            if (strtolower((string) $index['name']) === self::UNIQUE_INDEX) {
                return $index;
            }
        }

        return null;
    }
};
