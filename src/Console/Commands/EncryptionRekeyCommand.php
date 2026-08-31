<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lvntr\StarterKit\Console\Concerns\HoldsEncryptionRotationLock;
use Lvntr\StarterKit\Domain\Setting\SettingService;
use Lvntr\StarterKit\StarterKitServiceProvider;
use Lvntr\StarterKit\Support\Encryption\DataCrypt;
use Lvntr\StarterKit\Support\Encryption\DataEncrypterFactory;
use Lvntr\StarterKit\Support\Encryption\EncrypterCoverage;
use Throwable;

/**
 * Moves every value the kit stores encrypted onto the PRIMARY data-encryption
 * key, one bounded, locked chunk at a time.
 *
 * ## What it exists for
 *
 * {@see DataEncrypterFactory} keeps every previously configured key in the READ
 * chain, so an install that adopts `DATA_ENCRYPTION_KEY` (or rotates it) keeps
 * working with no command run at all. That chain is what makes adoption safe —
 * and it is also a liability: as long as a row still needs an old key to be
 * read, `DATA_ENCRYPTION_PREVIOUS_KEYS` can never be cleared, and the old key
 * has to travel with the app forever. This command closes the rotation: after a
 * clean run every scanned value decrypts with the primary key ALONE.
 *
 * ## The safety property (read this before changing anything below)
 *
 * A row that NO configured key can decrypt is left BYTE-FOR-BYTE untouched. It
 * is counted, it is listed by identifier, and it makes the command exit `1`.
 * It is never nulled, never deleted, never overwritten with a re-encryption of
 * a failed decrypt — on any code path. That is the entire point: an unreadable
 * row still holds the ciphertext, so the operator who finds the missing key can
 * still recover it. A "cleanup" write would destroy the only copy.
 *
 * The corollary is in the failure message: the fix for an unreadable row is to
 * ADD the missing key to `DATA_ENCRYPTION_PREVIOUS_KEYS`, never to clear that
 * list. Clearing it is the actual irreversible loss, and it is exactly what an
 * operator reaches for when a tool reports "unreadable" without saying so.
 *
 * ## Ciphertext-level, never value-level
 *
 * Both surfaces are re-encrypted through `decryptString()`/`encryptString()`
 * (`$serialize = false`), which round-trips the INNER PLAINTEXT verbatim and
 * never interprets it. This matters for the 2FA columns: Fortify writes them
 * with `encrypt()`/`decrypt()` (`$serialize = true`), so the inner plaintext is
 * a `serialize()` payload. Reading it with `decryptString()` yields that
 * serialized string, and writing it back with `encryptString()` reproduces it
 * exactly — Fortify's own `decrypt()` still unserializes it to the same value.
 * Going through `decrypt()` here instead would (a) unserialize attacker-adjacent
 * stored data for no reason and (b) risk re-serializing to a different byte
 * string. The rekey therefore works for any writer, whatever its serialize flag.
 *
 * ## Attribution, and why not just `DataCrypt::decryptString()`
 *
 * The bound encrypter already tries every key in the chain, so it would happily
 * decrypt every row — and tell us NOTHING about which key did it. This command
 * builds one single-key {@see Encrypter} per chain entry and tries them in
 * order, so it can distinguish "already on the primary key" (skip, no write, no
 * churn) from "read with an old key" (rewrite) from "no key at all" (leave
 * alone). A wrong key cannot produce a false positive: the MAC (CBC) or the
 * AEAD tag (GCM) is verified before any plaintext is returned.
 *
 * ## Writes go through the query builder, deliberately
 *
 * `DB::table()->update()` rather than Eloquent: no model events, no observers,
 * no activity-log rows carrying the ciphertext, and — the reason that matters
 * most — no `updated_at` bump. A rekey is a storage-format change, not a
 * business change; touching `updated_at` on every user would corrupt "when was
 * this account last modified" for the whole install.
 *
 * Soft-deleted rows are included for the same reason: `DB::table()` bypasses
 * the global scope, and a soft-deleted user's 2FA secret must survive a restore.
 *
 * ## Concurrency
 *
 * Rekey belongs in a maintenance window. It is nevertheless written to survive
 * live traffic: each chunk re-reads its rows inside a transaction under
 * `lockForUpdate()` and decides from THAT read, so a `TwoFactorChallengeAction::
 * consumeRecoveryCode()` rewrite that lands between the paging read and the
 * write is seen (it decrypts with the primary key, so it is skipped) instead of
 * being clobbered with a stale blob. On SQLite the lock compiles to a no-op and
 * the transaction is serialized instead — the same reasoning the 2FA action
 * documents.
 *
 * @see DataEncrypterFactory for the key-resolution contract
 * @see DataCrypt
 */
final class EncryptionRekeyCommand extends Command
{
    use HoldsEncryptionRotationLock;

    public const SURFACE_SETTINGS = 'settings';

    public const SURFACE_TWO_FACTOR = 'two-factor';

    /**
     * Rows read, locked and rewritten per round trip.
     *
     * Small on purpose, and much smaller than the activity-log redactor's 500:
     * every row costs one HMAC + one AES pass PER KEY IN THE CHAIN, and the
     * chunk holds row locks for that whole time. 200 keeps a chunk in the low
     * milliseconds even with four keys configured.
     */
    public const DEFAULT_CHUNK_SIZE = 200;

    private const MAX_CHUNK_SIZE = 2000;

    /**
     * Identifiers printed per surface before the list is truncated.
     *
     * A install that lost its key entirely would otherwise print one line per
     * row. The count is always reported in full; only the list is capped.
     */
    private const MAX_LISTED_IDENTIFIERS = 50;

    /**
     * Accepted `--only` spellings, mapped to the canonical surface name.
     *
     * @var array<string, string>
     */
    private const SURFACE_ALIASES = [
        'settings' => self::SURFACE_SETTINGS,
        'setting' => self::SURFACE_SETTINGS,
        'two-factor' => self::SURFACE_TWO_FACTOR,
        'two_factor' => self::SURFACE_TWO_FACTOR,
        'twofactor' => self::SURFACE_TWO_FACTOR,
        '2fa' => self::SURFACE_TWO_FACTOR,
    ];

    /**
     * Cache key {@see SettingService::allGrouped()}
     * stores its decrypted snapshot under. Duplicated rather than imported
     * because the service exposes no flush method; keep the two in step.
     */
    private const SETTINGS_CACHE_KEY = 'settings';

    protected $signature = 'encryption:rekey
        {--dry-run : Perform every decrypt attempt and print the identical summary without writing a single byte}
        {--only= : Limit the run to one surface: settings or two-factor (comma-separated to combine)}
        {--chunk=200 : Rows read, locked and rewritten per round trip (max 2000)}';

    protected $description = 'Re-encrypt settings and 2FA secrets onto the primary data-encryption key. A row no key can read is left untouched and reported.';

    /**
     * One single-key encrypter per chain entry, primary at index 0.
     *
     * @var list<Encrypter>
     */
    private array $encrypters = [];

    /**
     * Printable source label per chain entry, parallel to {@see self::$encrypters}.
     *
     * This is the ONLY part of the key chain that may be printed or logged;
     * `DataEncrypterFactory::keys()` documents the split.
     *
     * @var list<string>
     */
    private array $keySources = [];

    public function handle(): int
    {
        // A dry run reads and reports; it writes nothing, so it neither needs
        // the rotation lock nor should it block a real rotation waiting on one.
        if ((bool) $this->option('dry-run')) {
            return $this->rekey();
        }

        return $this->withRotationLock(fn (): int => $this->rekey());
    }

    private function rekey(): int
    {
        $chunkSize = $this->resolveChunkSize();

        if ($chunkSize === null) {
            return self::INVALID;
        }

        $selected = $this->resolveSelectedSurfaces();

        if ($selected === null) {
            return self::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');

        $factory = app(DataEncrypterFactory::class);

        // Drop any memoised chain first. In a real CLI run nothing is memoised
        // yet, so this is free; in a long-lived process (Octane, a test that
        // swapped a key) it is what guarantees the command rekeys onto the key
        // the CONFIG names right now rather than onto a stale one. Re-deriving
        // is pure: it either reproduces the same chain or throws before a
        // single row is read.
        $factory->flush();

        try {
            $chain = $factory->keys();
            $cipher = $factory->cipher();
            $usingDedicatedKey = $factory->usingDedicatedKey();
        } catch (Throwable $e) {
            // Fail closed and fail EARLY: nothing has been read or written yet.
            // The factory's messages name the offending env var and never its
            // value, so passing the message through is safe.
            $this->error('Encryption keys could not be resolved. Nothing was read and nothing was written.');
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->encrypters = array_map(
            static fn (array $entry): Encrypter => new Encrypter($entry['key'], $cipher),
            $chain,
        );
        $this->keySources = array_column($chain, 'source');

        $this->line(sprintf(
            'Primary key: %s (cipher %s). Read chain: %s.',
            $this->keySources[0],
            $cipher,
            implode(' -> ', $this->keySources),
        ));

        if (! $usingDedicatedKey) {
            $this->warn(
                'No '.DataEncrypterFactory::PRIMARY_ENV_KEY.' is configured, so the primary key is '
                .DataEncrypterFactory::APP_ENV_KEY.'. Rekeying onto '.DataEncrypterFactory::APP_ENV_KEY
                .' leaves this data one `php artisan key:generate` away from being unreadable — run '
                .'`php artisan encryption:key` first if that was not deliberate.'
            );
        }

        if (count($this->encrypters) === 1) {
            $this->line('Only one key is configured, so no value can move between keys; this run reports which values that key can read.');
        }

        if (! $this->coverageAllows($chain, $selected)) {
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('DRY RUN — every decrypt is attempted, nothing is written.');
        }

        $reports = [];
        $settingsTouched = false;

        foreach ($this->surfaces() as $surface) {
            if (! in_array($surface['name'], $selected, true)) {
                continue;
            }

            $report = $this->runSurface($surface, $chunkSize, $dryRun);
            $reports[] = $report;

            if ($surface['name'] === self::SURFACE_SETTINGS && $report['status'] === 'ok') {
                $settingsTouched = true;
            }
        }

        // The cached snapshot holds DECRYPTED values, so a rekey does not
        // invalidate it on its own. It is flushed anyway because the run may
        // have been preceded by a key change: entries cached while a value was
        // unreadable are stored as null (SettingService swallows the decrypt
        // failure), and only a flush turns those back into real values.
        if ($settingsTouched && ! $dryRun) {
            Cache::forget(self::SETTINGS_CACHE_KEY);
        }

        return $this->report($reports, $dryRun);
    }

    /**
     * Refuse a run whose selected surfaces the kit cannot re-encrypt.
     *
     * A rekey rewrites every value onto the PRIMARY key. That is only safe if
     * the code that will read those values afterwards uses the same key — and
     * for the two-factor surface it may not: Fortify reads through
     * `Fortify::$encrypter ?? Model::$encrypter ?? Crypt`, and the kit
     * deliberately does not overwrite a consumer that set either one
     * ({@see StarterKitServiceProvider::configureDataEncryption()}).
     * Rewriting those columns onto a key that encrypter does not hold turns
     * every 2FA login into a failed challenge — the exact loss this command
     * exists to prevent, caused by the command itself.
     *
     * So it stops BEFORE reading a single row and names the surface. The run is
     * refused rather than silently narrowed: an operator who asked for a full
     * rekey and got a partial one, reported as success, is worse off than one
     * who got an error naming the surface and the flag that excludes it.
     *
     * A run whose selected surfaces are all covered is never affected.
     *
     * @param  list<array{source: string, key: string}>  $chain
     * @param  list<string>  $selected
     */
    private function coverageAllows(array $chain, array $selected): bool
    {
        $probe = new EncrypterCoverage;

        if (! $probe->configBlockPresent()) {
            // Not a refusal: rekeying onto the APP_KEY fallback is a legitimate,
            // reversible operation (APP_KEY stays in the chain). It IS a warning,
            // because the operator almost certainly meant to rekey onto the
            // dedicated key they configured and which this install cannot see.
            $this->warn(sprintf(
                'The `starter-kit.encryption` config block is ABSENT, so %s reads null and the primary key is the '
                .'%s fallback%s. Re-publish config/starter-kit.php (vendor:publish --tag=starter-kit-config '
                .'--force) before rekeying if you meant to move onto a dedicated key.',
                DataEncrypterFactory::PRIMARY_ENV_KEY,
                DataEncrypterFactory::APP_ENV_KEY,
                $probe->primaryKeyPresentInEnvironment()
                    ? sprintf(' even though %s IS set in this environment', DataEncrypterFactory::PRIMARY_ENV_KEY)
                    : '',
            ));
        }

        $blocked = [];

        foreach ($probe->report($chain) as $entry) {
            if (! in_array($entry['surface'], $selected, true)) {
                continue;
            }

            if (EncrypterCoverage::isNotVouched($entry['status'])) {
                $blocked[] = $entry;
            }
        }

        if ($blocked === []) {
            return true;
        }

        $this->error('Refusing to rekey: a selected surface is served by an encrypter this kit cannot re-encrypt for.');

        foreach ($blocked as $entry) {
            $this->error(sprintf('  %-11s %s', $entry['surface'], $entry['detail']));
            $this->warn(sprintf('  %-11s serving encrypter: %s (kit-built: %s)', '', $entry['encrypter'], $entry['kit_built'] ? 'yes' : 'no'));
        }

        $remaining = array_values(array_diff($selected, array_column($blocked, 'surface')));

        $this->warn($remaining === []
            ? 'Nothing was read and nothing was written. Point that surface at the kit\'s data encrypter, or rekey it with your own tooling.'
            : sprintf(
                'Nothing was read and nothing was written. Re-run with `--only=%s` to rekey the surface(s) this kit does cover.',
                implode(',', $remaining),
            ));

        return false;
    }

    /**
     * Run one surface end to end.
     *
     * @param  array<string, mixed>  $surface
     * @return array<string, mixed>
     */
    private function runSurface(array $surface, int $chunkSize, bool $dryRun): array
    {
        /** @var string|null $connectionName */
        $connectionName = $surface['connection'];
        /** @var string $table */
        $table = $surface['table'];
        /** @var string $keyName */
        $keyName = $surface['key'];

        $report = [
            'name' => $surface['name'],
            'table' => $table,
            'status' => 'ok',
            'missing' => '',
            'scanned' => 0,
            'primary' => 0,
            'rekeyed' => 0,
            'unreadable' => 0,
            'failed' => 0,
            'bySource' => [],
            'identifiers' => [],
            'overflow' => 0,
        ];

        $schema = Schema::connection($connectionName);

        // A missing table is a valid install shape, not an error: the kit ships
        // to apps that never published the settings migration, and the 2FA
        // columns only exist where Fortify's migration ran.
        if (! $schema->hasTable($table)) {
            $report['status'] = 'missing-table';

            return $report;
        }

        if (! $schema->hasColumn($table, $keyName)) {
            $report['status'] = 'missing-column';
            $report['missing'] = $keyName;

            return $report;
        }

        /** @var list<string> $required */
        $required = $surface['requires'];

        foreach ($required as $column) {
            if (! $schema->hasColumn($table, $column)) {
                $report['status'] = 'missing-column';
                $report['missing'] = $column;

                return $report;
            }
        }

        /** @var list<string> $candidates */
        $candidates = $surface['columns'];

        $columns = array_values(array_filter(
            $candidates,
            static fn (string $column): bool => $schema->hasColumn($table, $column),
        ));

        if ($columns === []) {
            $report['status'] = 'missing-column';
            $report['missing'] = implode(', ', $candidates);

            return $report;
        }

        // Context columns are for the human-readable identifier only; a custom
        // model without them still rekeys, it just reports by primary key.
        /** @var list<string> $contextCandidates */
        $contextCandidates = $surface['context'];

        $context = array_values(array_filter(
            $contextCandidates,
            static fn (string $column): bool => $schema->hasColumn($table, $column),
        ));

        $select = array_values(array_unique(array_merge([$keyName], $context, $columns)));

        $connection = DB::connection($connectionName);

        $query = $connection->table($table)->select($select);
        $this->applyFilter($query, $surface, $columns);

        // chunkById, never chunk(): keyset paging only ever moves forward, so a
        // rewritten row cannot renumber the result set under the pager. The
        // rewrite never changes the key or the filter columns, so no row can
        // fall out of or into the set because of this command.
        $query->chunkById(
            $chunkSize,
            function (Collection $rows) use (&$report, $connection, $surface, $table, $keyName, $select, $columns, $dryRun): void {
                if ($dryRun) {
                    // Read-only on purpose: no transaction, no row locks. A dry
                    // run is safe to point at a live production database.
                    $this->processRows($rows, $report, $surface, $keyName, $columns);

                    return;
                }

                /** @var list<mixed> $ids */
                $ids = $rows->pluck($keyName)->all();

                if ($ids === []) {
                    return;
                }

                // One SHORT transaction per chunk — never one across the table.
                // A crash mid-run therefore leaves whole chunks applied, and
                // every applied chunk is already readable by the primary key.
                $connection->transaction(function () use ($ids, &$report, $connection, $surface, $table, $keyName, $select, $columns): void {
                    $locked = $connection->table($table)->select($select)->whereIn($keyName, $ids);

                    // The filter is re-applied under the lock, not assumed from
                    // the paging read: a concurrent write may have cleared the
                    // value or the `encrypted` flag in between, and such a row
                    // must drop out rather than be rewritten from a stale read.
                    $this->applyFilter($locked, $surface, $columns);

                    // Deterministic lock order so two concurrent rekey runs
                    // queue up instead of deadlocking.
                    $rows = $locked->orderBy($keyName)->lockForUpdate()->get();

                    // Decisions come from the LOCKED read. That is what makes
                    // the decrypt/re-encrypt/write a real read-modify-write and
                    // keeps a 2FA recovery-code consumption from being undone.
                    $updates = $this->processRows($rows, $report, $surface, $keyName, $columns);

                    foreach ($updates as [$key, $payload]) {
                        $connection->table($table)->where($keyName, $key)->update($payload);
                    }
                });
            },
            $keyName,
        );

        return $report;
    }

    /**
     * Classify every value in a page and collect the writes it justifies.
     *
     * Returns a LIST OF PAIRS, not a key => payload map: PHP silently casts a
     * numeric-string array key to int, which would rewrite the identity of a
     * string primary key (the kit's `users.id` is a UUID) before it ever
     * reached the WHERE clause.
     *
     * @param  Collection<int, object>  $rows
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $surface
     * @param  list<string>  $columns
     * @return list<array{0: mixed, 1: array<string, string>}>
     */
    private function processRows(Collection $rows, array &$report, array $surface, string $keyName, array $columns): array
    {
        $updates = [];

        foreach ($rows as $row) {
            $report['scanned']++;
            $payload = [];

            foreach ($columns as $column) {
                $raw = $row->{$column} ?? null;

                // Genuinely absent: the two-factor filter is an OR, so a row
                // with only one of the two columns set is expected. Not a
                // finding, not counted.
                if ($raw === null) {
                    continue;
                }

                // Present but not a usable ciphertext (empty string, or a
                // non-string a custom cast produced). No key can read it and
                // nothing may be written over it — it is reported exactly like
                // any other unreadable value.
                if (! is_string($raw) || trim($raw) === '') {
                    $report['unreadable']++;
                    $this->recordIdentifier($report, $surface, $row, $keyName, $column);

                    continue;
                }

                $outcome = $this->rekeyValue($raw);

                if ($outcome['status'] === 'primary') {
                    $report['primary']++;

                    continue;
                }

                if ($outcome['status'] === 'unreadable') {
                    $report['unreadable']++;
                    $this->recordIdentifier($report, $surface, $row, $keyName, $column);

                    continue;
                }

                if ($outcome['status'] === 'failed') {
                    $report['failed']++;
                    $this->recordIdentifier($report, $surface, $row, $keyName, $column);

                    continue;
                }

                $report['rekeyed']++;
                $source = $this->keySources[$outcome['index']];
                $report['bySource'][$source] = ($report['bySource'][$source] ?? 0) + 1;

                $payload[$column] = $outcome['value'];
            }

            if ($payload !== []) {
                $updates[] = [$row->{$keyName}, $payload];
            }
        }

        return $updates;
    }

    /**
     * Decide what happens to ONE ciphertext. Pure: it reads, it decides, it
     * returns — it never writes and never mutates its input.
     *
     * Outcomes:
     *   `primary`    — the primary key read it; nothing to do, no write, no churn.
     *   `rekeyed`    — an older key read it; `value` is the re-encrypted payload,
     *                  already verified to decrypt back to the same bytes.
     *   `failed`     — readable, but the re-encrypt or its verification did not
     *                  round-trip. NOTHING is written; the row keeps the payload
     *                  an old key can still read.
     *   `unreadable` — no configured key read it. NOTHING is written, ever.
     *
     * The re-encryption is verified before it is offered for writing. It costs
     * one extra AES pass on the rare rewritten row and it makes it structurally
     * impossible for this command to store a payload the primary key cannot
     * read — which is the one failure mode that would turn a rekey into silent
     * data loss.
     *
     * @return array{status: string, index: int|null, value: string|null}
     */
    private function rekeyValue(string $cipherText): array
    {
        foreach ($this->encrypters as $index => $encrypter) {
            try {
                // DecryptException specifically, never Throwable: a bad MAC, a
                // wrong key and a malformed payload all raise it, while a real
                // bug (a TypeError from a driver returning something unexpected)
                // must surface loudly instead of being misreported as data that
                // needs a key nobody has.
                $plain = $encrypter->decryptString($cipherText);
            } catch (DecryptException) {
                continue;
            }

            if ($index === 0) {
                unset($plain);

                return ['status' => 'primary', 'index' => 0, 'value' => null];
            }

            try {
                $rewritten = $this->encrypters[0]->encryptString($plain);
                $verified = $this->encrypters[0]->decryptString($rewritten);
            } catch (Throwable) {
                // Throwable here, because this is the WRITE side: whatever went
                // wrong, the answer is to write nothing.
                unset($plain);

                return ['status' => 'failed', 'index' => $index, 'value' => null];
            }

            $matches = hash_equals($plain, $verified);

            unset($plain, $verified);

            return $matches
                ? ['status' => 'rekeyed', 'index' => $index, 'value' => $rewritten]
                : ['status' => 'failed', 'index' => $index, 'value' => null];
        }

        return ['status' => 'unreadable', 'index' => null, 'value' => null];
    }

    /**
     * Narrow a query to the rows this surface owns.
     *
     * The NOT NULL clause is GROUPED on purpose: `chunkById` appends
     * `and <key> > ?` at the TOP level, and an ungrouped OR chain would swallow
     * that condition and page over the same rows forever.
     *
     * @param  array<string, mixed>  $surface
     * @param  list<string>  $columns
     */
    private function applyFilter(Builder $query, array $surface, array $columns): void
    {
        /** @var array<string, mixed> $predicates */
        $predicates = $surface['where'];

        foreach ($predicates as $column => $value) {
            $query->where($column, $value);
        }

        $query->where(static function (Builder $group) use ($columns): void {
            foreach ($columns as $column) {
                $group->orWhereNotNull($column);
            }
        });
    }

    /**
     * Remember which value could not be moved, capped so a fully orphaned table
     * cannot print a million lines.
     *
     * Identifiers only — a settings path or a primary key. Never a value.
     *
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $surface
     */
    private function recordIdentifier(array &$report, array $surface, object $row, string $keyName, string $column): void
    {
        if (count($report['identifiers']) >= self::MAX_LISTED_IDENTIFIERS) {
            $report['overflow']++;

            return;
        }

        /** @var callable(object, string, string): string $identifier */
        $identifier = $surface['identifier'];

        $report['identifiers'][] = $identifier($row, $keyName, $column);
    }

    /**
     * Print the per-surface summary and derive the exit code.
     *
     * Exit `0` means every scanned value now reads with the primary key alone,
     * so a deploy script may clear `DATA_ENCRYPTION_PREVIOUS_KEYS`. Exit `1`
     * means at least one value did not make it and the old keys must stay.
     * `--dry-run` uses the same rule, so it can gate a deploy before the write.
     *
     * @param  list<array<string, mixed>>  $reports
     */
    private function report(array $reports, bool $dryRun): int
    {
        if ($reports === []) {
            $this->warn('No surface selected — nothing to do.');

            return self::SUCCESS;
        }

        $unreadable = 0;
        $failed = 0;
        $rekeyed = 0;

        foreach ($reports as $report) {
            if ($report['status'] === 'missing-table') {
                $this->line(sprintf(
                    '%-11s skipped — table [%s] is not present on this install.',
                    $report['name'],
                    $report['table'],
                ));

                continue;
            }

            if ($report['status'] === 'missing-column') {
                $this->line(sprintf(
                    '%-11s skipped — table [%s] has no [%s] column.',
                    $report['name'],
                    $report['table'],
                    $report['missing'],
                ));

                continue;
            }

            $unreadable += $report['unreadable'];
            $failed += $report['failed'];
            $rekeyed += $report['rekeyed'];

            $this->line(sprintf(
                '%-11s %d row(s) scanned — %d value(s) already on the primary key, %d %s%s, %d unreadable%s.',
                $report['name'],
                $report['scanned'],
                $report['primary'],
                $report['rekeyed'],
                $dryRun ? 'would be rekeyed' : 'rekeyed',
                $this->formatSources($report['bySource']),
                $report['unreadable'],
                $report['failed'] > 0 ? sprintf(', %d re-encrypt failure(s)', $report['failed']) : '',
            ));

            if ($report['identifiers'] !== []) {
                $this->warn(sprintf('%s — left untouched:', $report['name']));

                foreach ($report['identifiers'] as $identifier) {
                    $this->warn('  '.$identifier);
                }

                if ($report['overflow'] > 0) {
                    $this->warn(sprintf('  ... and %d more (list capped at %d).', $report['overflow'], self::MAX_LISTED_IDENTIFIERS));
                }
            }
        }

        if ($unreadable > 0 || $failed > 0) {
            $this->newLine();
            $this->error(sprintf(
                '%d value(s) could not be moved onto the primary key and were left BYTE-FOR-BYTE untouched — '
                .'nothing was deleted, nulled or overwritten.',
                $unreadable + $failed,
            ));
            $this->error(
                'The key that wrote them is missing from the chain. Add it to '
                .DataEncrypterFactory::PREVIOUS_ENV_KEY.' and run this command again. '
                .'Do NOT clear '.DataEncrypterFactory::PREVIOUS_ENV_KEY.' — a key removed from the chain cannot be recovered.'
            );

            return self::FAILURE;
        }

        $this->newLine();

        if ($dryRun) {
            $this->info(sprintf(
                'Dry run: nothing was written. %d value(s) would be rekeyed; every scanned value is readable.',
                $rekeyed,
            ));

            return self::SUCCESS;
        }

        $this->info('Every scanned value now decrypts with the primary key alone.');
        $this->line(
            'Next: run `php artisan encryption:health`, and clear '
            .DataEncrypterFactory::PREVIOUS_ENV_KEY.' only after it reports OK.'
        );

        return self::SUCCESS;
    }

    /**
     * Render the per-key breakdown of rewritten values.
     *
     * Prints the `source` LABEL of each chain entry, which
     * `DataEncrypterFactory::keys()` designates as the only printable member.
     *
     * @param  array<string, int>  $bySource
     */
    private function formatSources(array $bySource): string
    {
        if ($bySource === []) {
            return '';
        }

        $parts = [];

        foreach ($bySource as $source => $count) {
            $parts[] = $source.': '.$count;
        }

        return ' (from '.implode(', ', $parts).')';
    }

    /**
     * The surfaces this command knows how to rekey.
     *
     * @return list<array<string, mixed>>
     */
    private function surfaces(): array
    {
        [$settingsConnection, $settingsTable, $settingsKey] = $this->resolveTarget('App\\Models\\Setting', 'settings', 'id');
        [$userConnection, $userTable, $userKey] = $this->resolveTarget($this->userModel(), 'users', 'id');

        return [
            [
                'name' => self::SURFACE_SETTINGS,
                'connection' => $settingsConnection,
                'table' => $settingsTable,
                'key' => $settingsKey,
                'columns' => ['value'],
                // Without the flag column there is no way to tell an encrypted
                // row from a plaintext one, and guessing would mean feeding
                // plaintext settings to a decrypt loop and reporting the whole
                // table as unreadable. Skip instead.
                'requires' => ['encrypted'],
                'context' => ['group', 'key'],
                'where' => ['encrypted' => 1],
                'identifier' => static fn (object $row, string $keyName, string $column): string => sprintf(
                    '%s.%s',
                    is_scalar($row->group ?? null) ? (string) $row->group : '?',
                    is_scalar($row->key ?? null) ? (string) $row->key : '?',
                ),
            ],
            [
                'name' => self::SURFACE_TWO_FACTOR,
                'connection' => $userConnection,
                'table' => $userTable,
                'key' => $userKey,
                'columns' => ['two_factor_secret', 'two_factor_recovery_codes'],
                'requires' => [],
                'context' => [],
                'where' => [],
                'identifier' => static fn (object $row, string $keyName, string $column): string => sprintf(
                    '%s=%s (%s)',
                    $keyName,
                    is_scalar($row->{$keyName} ?? null) ? (string) $row->{$keyName} : '?',
                    $column,
                ),
            ],
        ];
    }

    /**
     * The configured user model, falling back to the kit's stub class name.
     */
    private function userModel(): string
    {
        $model = config('auth.providers.users.model');

        return is_string($model) && $model !== '' ? $model : 'App\\Models\\User';
    }

    /**
     * Resolve connection + table + primary key off a model class when the app
     * has one, and off the conventional names when it does not.
     *
     * The same shape {@see RedactActivityLogSecretsCommand::resolveTarget()}
     * uses, and for the same reason: a consumer that repointed a model at
     * another table or another connection must have THAT one rekeyed, and the
     * key NAME has to come from the model too, since the run pages and updates
     * by it.
     *
     * @return array{0: string|null, 1: string, 2: string}
     */
    private function resolveTarget(string $modelClass, string $fallbackTable, string $fallbackKey): array
    {
        if ($modelClass !== '' && class_exists($modelClass)) {
            try {
                $instance = new $modelClass;

                if ($instance instanceof Model) {
                    $keyName = $instance->getKeyName();

                    return [
                        $instance->getConnectionName(),
                        $instance->getTable(),
                        // A composite or absent key makes keyset paging
                        // meaningless; fall back rather than build a broken
                        // query that would abort halfway through a rekey.
                        is_string($keyName) && $keyName !== '' ? $keyName : $fallbackKey,
                    ];
                }
            } catch (Throwable) {
                // A model that cannot be constructed without arguments is not
                // worth failing the whole run over — fall through.
            }
        }

        return [null, $fallbackTable, $fallbackKey];
    }

    /**
     * Validate `--chunk`. Returns null when the option is unusable, in which
     * case the caller exits INVALID without touching the database.
     *
     * Rejected rather than coerced: `--chunk=abc` silently becoming 1 would
     * turn a maintenance-window run into a row-at-a-time crawl with no warning.
     */
    private function resolveChunkSize(): ?int
    {
        $raw = $this->option('chunk');

        if ($raw === null || $raw === '') {
            return self::DEFAULT_CHUNK_SIZE;
        }

        if (! is_string($raw) || ! ctype_digit($raw) || (int) $raw < 1) {
            $this->error('--chunk must be a positive integer.');

            return null;
        }

        return min(self::MAX_CHUNK_SIZE, (int) $raw);
    }

    /**
     * Resolve `--only` to a list of canonical surface names. Returns null on an
     * unknown value — an unrecognised surface must not silently become "all",
     * which would rewrite data the operator deliberately scoped out.
     *
     * @return list<string>|null
     */
    private function resolveSelectedSurfaces(): ?array
    {
        $raw = $this->option('only');

        if ($raw === null || ! is_string($raw) || trim($raw) === '') {
            return [self::SURFACE_SETTINGS, self::SURFACE_TWO_FACTOR];
        }

        $selected = [];

        foreach (explode(',', $raw) as $item) {
            $item = strtolower(trim($item));

            if ($item === '') {
                continue;
            }

            if (! isset(self::SURFACE_ALIASES[$item])) {
                $this->error(sprintf(
                    'Unknown surface [%s] for --only. Valid values: %s, %s.',
                    $item,
                    self::SURFACE_SETTINGS,
                    self::SURFACE_TWO_FACTOR,
                ));

                return null;
            }

            $canonical = self::SURFACE_ALIASES[$item];

            if (! in_array($canonical, $selected, true)) {
                $selected[] = $canonical;
            }
        }

        if ($selected === []) {
            $this->error('--only was given but named no surface.');

            return null;
        }

        return $selected;
    }
}
