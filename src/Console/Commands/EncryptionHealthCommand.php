<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Lvntr\StarterKit\Domain\Setting\SettingService;
use Lvntr\StarterKit\Support\Encryption\DataEncrypterFactory;
use Lvntr\StarterKit\Support\Encryption\EncrypterCoverage;
use Throwable;

/**
 * Answers ONE question with evidence: can `DATA_ENCRYPTION_PREVIOUS_KEYS` be
 * cleared without losing data?
 *
 * ## Why the question needs a command at all
 *
 * {@see DataEncrypterFactory} keeps every configured key in the READ chain, so
 * an install keeps working during and after a key change with nothing run. The
 * cost is that "it works" tells the operator nothing about WHICH key is doing
 * the work — and the moment they clear the previous-key list on a hunch, every
 * row still riding an old key becomes permanently unreadable. Worse, it fails
 * silently: {@see SettingService} swallows the
 * DecryptException and returns null, and an unreadable `two_factor_secret`
 * locks a user out at the challenge step instead of raising anything.
 *
 * This command is therefore the gate in front of that edit: it attributes every
 * stored ciphertext to the key that can actually read it.
 *
 * ## Strictly read-only
 *
 * No key is generated, no `.env` line is touched, no row is written — not even
 * a cleanup of a row nothing can read. It opens no transaction and takes no
 * lock, so it is safe to point at a live production database. Its only side
 * effect is CPU: one HMAC/AEAD verification per value per key tried.
 *
 * ## Verdicts (exit code is the machine-readable half)
 *
 *   0 — {@see self::VERDICT_SAFE}: every scanned value reads with the PRIMARY
 *       key alone, every surface was fully scanned.
 *   1 — {@see self::VERDICT_REKEY_REQUIRED}: at least one value needs a
 *       non-primary key. Nothing is lost yet; clearing the previous-key list
 *       WOULD lose it. Run `encryption:rekey`.
 *   1 — {@see self::VERDICT_INCOMPLETE}: a surface could not be fully scanned,
 *       so "safe" cannot be asserted about it.
 *   1 — {@see self::VERDICT_NOT_COVERED}: a surface is served by an encrypter
 *       the kit did not build and cannot vouch for, or the
 *       `starter-kit.encryption` config block is absent so DATA_ENCRYPTION_KEY
 *       is inert. The attribution below is a true statement about the stored
 *       bytes and a misleading one about this install.
 *   2 — {@see self::VERDICT_UNREADABLE}: a value NO configured key can read.
 *       The key that wrote it is missing from `.env`; it must be ADDED, never
 *       cleared.
 *   2 — {@see self::VERDICT_KEY_ERROR}: the key chain itself does not resolve,
 *       so nothing could be attributed at all.
 *
 * Exit `0` is emitted for the first verdict ONLY. Every ambiguity — a missing
 * table, a query that blew up, a value that is present but is not a usable
 * ciphertext — DOWNGRADES the verdict and never upgrades it, because a false
 * "safe to clear" is the one output of this command that destroys data.
 *
 * ## Surface definitions are duplicated from EncryptionRekeyCommand ON PURPOSE
 *
 * The two commands must agree on WHAT is encrypted, and they are deliberately
 * separate code paths: this one may never grow a write, and that one may never
 * grow a "just report it" shortcut. Keep {@see self::surfaces()} in step with
 * {@see EncryptionRekeyCommand::surfaces()} — a surface added there and not
 * here would make this command report "safe to clear" about data it never
 * looked at.
 *
 * @see DataEncrypterFactory for the key-resolution contract
 * @see EncryptionRekeyCommand for the write side
 */
final class EncryptionHealthCommand extends Command
{
    public const VERDICT_SAFE = 'safe-to-clear';

    public const VERDICT_REKEY_REQUIRED = 'rekey-required';

    public const VERDICT_INCOMPLETE = 'incomplete';

    /**
     * A surface is served by an encrypter this run could not vouch for, or the
     * `starter-kit.encryption` config block is absent so the configured key is
     * inert. Nothing is lost, but the attribution below does not describe the
     * install's actual read/write path, so "safe to clear" cannot be asserted
     * from it.
     */
    public const VERDICT_NOT_COVERED = 'not-covered';

    public const VERDICT_UNREADABLE = 'unreadable';

    public const VERDICT_KEY_ERROR = 'key-error';

    /**
     * Not safe to clear, but nothing is lost: a rekey (or a fuller scan) fixes
     * it. Mirrors `sk:doctor`'s "warn" exit code.
     */
    private const EXIT_NOT_CLEAN = 1;

    /**
     * Data that no configured key can read exists, or the chain itself is
     * broken. Mirrors `sk:doctor`'s "fail" exit code.
     */
    private const EXIT_BROKEN = 2;

    /**
     * Rows read per round trip. Read-only and unlocked, so this is purely about
     * memory; the per-row cost is one decrypt attempt PER KEY in the chain.
     */
    private const CHUNK_SIZE = 200;

    /**
     * Unreadable identifiers printed per surface before the list truncates. The
     * COUNT is always reported in full; only the list is capped, so an install
     * that lost its key entirely cannot print one line per row.
     */
    private const MAX_LISTED_IDENTIFIERS = 50;

    protected $signature = 'encryption:health
        {--json : Emit a machine-readable report instead of the table, mirroring `sk:doctor --json`}';

    protected $description = 'Report which key each encrypted value needs, and whether DATA_ENCRYPTION_PREVIOUS_KEYS can be cleared. Read-only.';

    /**
     * One single-key encrypter per chain entry, primary at index 0.
     *
     * Attribution is impossible through the bound encrypter: it tries the whole
     * chain and reports only success. Trying one key at a time is what turns
     * "it decrypts" into "it decrypts with THIS key". A wrong key cannot produce
     * a false positive — the MAC (CBC) or the AEAD tag (GCM) is verified before
     * any plaintext is returned.
     *
     * @var list<Encrypter>
     */
    private array $encrypters = [];

    /**
     * Printable source label per chain entry, parallel to {@see self::$encrypters}.
     *
     * The ONLY part of the key chain that may be printed or logged;
     * {@see DataEncrypterFactory::keys()} documents that split.
     *
     * @var list<string>
     */
    private array $keySources = [];

    /**
     * Whether APP_KEY's MATERIAL is in the read chain — not whether an entry
     * happens to carry the APP_KEY label.
     *
     * The two diverge in exactly the state the runbook produces. After a first
     * adoption, `encryption:key` retires APP_KEY into
     * DATA_ENCRYPTION_PREVIOUS_KEYS[0]; the factory's chain builder then
     * dedupes the invariant "APP_KEY last" entry away as a duplicate, so no
     * entry is labelled APP_KEY any more even though its bytes are right there
     * at position 1. Reporting "APP_KEY: NOT in the read chain" at that moment
     * would tell the operator their pre-adoption rows are unreadable — and the
     * likely reaction is to go looking for a key that is not missing.
     *
     * Holds the source LABEL of the chain slot carrying APP_KEY's material, or
     * null when it genuinely is not in the chain.
     */
    private ?string $appKeyChainSource = null;

    public function handle(): int
    {
        $json = (bool) $this->option('json');

        $factory = app(DataEncrypterFactory::class);

        // Re-derive rather than trust a memoised chain: in a long-lived process
        // (Octane, a test that swapped a key) the memo may predate the current
        // config, and a health report about a key set that is no longer in
        // force is worse than no report.
        $factory->flush();

        try {
            $chain = $factory->keys();
            $cipher = $factory->cipher();
            $usingDedicatedKey = $factory->usingDedicatedKey();
        } catch (Throwable $e) {
            // The factory's messages name the offending env var and never its
            // value, so the message is safe to pass through verbatim.
            return $this->reportKeyError($e->getMessage(), $json);
        }

        $this->encrypters = array_map(
            static fn (array $entry): Encrypter => new Encrypter($entry['key'], $cipher),
            $chain,
        );
        $this->keySources = array_column($chain, 'source');
        $this->appKeyChainSource = $this->appKeyChainSource($chain);

        $keys = $this->describeKeys($cipher, $usingDedicatedKey);

        // WHO serves each surface, as opposed to which key opened its stored
        // bytes. Read-only and cheap (no row is touched); it is what turns an
        // attribution report into a statement about this install.
        $coverageProbe = new EncrypterCoverage;
        $coverage = $coverageProbe->report($chain);
        $configBlock = [
            'present' => $coverageProbe->configBlockPresent(),
            'configuration_cached' => $coverageProbe->configurationIsCached(),
            'primary_key_in_environment' => $coverageProbe->primaryKeyPresentInEnvironment(),
        ];

        $surfaces = [];

        foreach ($this->surfaces() as $surface) {
            $surfaces[] = $this->scanSurface($surface);
        }

        $summary = $this->summarize($surfaces, $coverage, $configBlock);
        $verdict = $this->decideVerdict($summary);

        return $json
            ? $this->outputJson($keys, $surfaces, $summary, $verdict, $coverage, $configBlock)
            : $this->outputText($keys, $surfaces, $summary, $verdict, $coverage, $configBlock);
    }

    /**
     * The source label of the chain slot whose MATERIAL is APP_KEY, or null.
     *
     * Compares bytes, not labels — see {@see self::$appKeyChainSource} for why
     * the label is unreliable after a first adoption. A malformed or absent
     * APP_KEY answers null: the factory would already have thrown on a
     * malformed value, so reaching here with one is not a state to interpret.
     *
     * @param  list<array{source: string, key: string}>  $chain
     */
    private function appKeyChainSource(array $chain): ?string
    {
        $raw = config('app.key');

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $material = str_starts_with($raw, 'base64:')
            ? base64_decode(substr($raw, 7), true)
            : $raw;

        if (! is_string($material) || $material === '') {
            return null;
        }

        foreach ($chain as $entry) {
            if (hash_equals($entry['key'], $material)) {
                return $entry['source'];
            }
        }

        return null;
    }

    /**
     * Describe the resolved chain in printable terms only.
     *
     * @return array<string, mixed>
     */
    private function describeKeys(string $cipher, bool $usingDedicatedKey): array
    {
        $previousPrefix = DataEncrypterFactory::PREVIOUS_ENV_KEY.'[';
        $appPreviousPrefix = DataEncrypterFactory::APP_PREVIOUS_ENV_KEY.'[';

        $previousInChain = 0;
        $appPreviousInChain = 0;
        $appKeyInChain = $this->appKeyChainSource !== null;

        foreach ($this->keySources as $source) {
            if (str_starts_with($source, $previousPrefix)) {
                $previousInChain++;
            }

            if (str_starts_with($source, $appPreviousPrefix)) {
                $appPreviousInChain++;
            }
        }

        return [
            'source' => $this->keySources[0],
            'using_dedicated_key' => $usingDedicatedKey,
            'cipher' => $cipher,
            'chain' => $this->keySources,
            // Counted from the CHAIN, not from the raw env string: the factory
            // drops blanks and de-duplicates, so a previous key identical to the
            // primary is not a key the data can be riding.
            'previous_keys_in_chain' => $previousInChain,
            'previous_keys_env_set' => $this->previousKeysEnvSet(),
            'app_previous_keys_in_chain' => $appPreviousInChain,
            'app_key_in_chain' => $appKeyInChain,
            'app_key_chain_source' => $this->appKeyChainSource,
        ];
    }

    /**
     * Whether `DATA_ENCRYPTION_PREVIOUS_KEYS` holds anything at all.
     *
     * Reported beside the in-chain count so "0 previous keys in the chain" while
     * the env var is populated is visible as what it is: entries that were
     * blank or duplicates of another key in the chain.
     */
    private function previousKeysEnvSet(): bool
    {
        $configured = config('starter-kit.encryption.previous_keys');

        if (is_array($configured)) {
            foreach ($configured as $item) {
                if (is_string($item) && trim($item) !== '') {
                    return true;
                }
            }

            return false;
        }

        return is_string($configured) && trim($configured) !== '';
    }

    /**
     * Attribute every value on one surface to the key that can read it.
     *
     * @param  array<string, mixed>  $surface
     * @return array<string, mixed>
     */
    private function scanSurface(array $surface): array
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
            'detail' => '',
            'scanned' => 0,
            'primary' => 0,
            'previous' => 0,
            'unreadable' => 0,
            'by_source' => [],
            'identifiers' => [],
            'overflow' => 0,
        ];

        try {
            $schema = Schema::connection($connectionName);

            // A missing table is a valid install shape (the settings migration
            // was never published, Fortify's columns were never added) — but it
            // is still a surface this run could NOT vouch for, so it downgrades
            // the verdict instead of being silently treated as clean.
            if (! $schema->hasTable($table)) {
                $report['status'] = 'missing-table';
                $report['detail'] = sprintf('table [%s] is not present on this install', $table);

                return $report;
            }

            /** @var list<string> $required */
            $required = $surface['requires'];

            foreach (array_merge([$keyName], $required) as $column) {
                if (! $schema->hasColumn($table, $column)) {
                    $report['status'] = 'missing-column';
                    $report['detail'] = sprintf('table [%s] has no [%s] column', $table, $column);

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
                $report['detail'] = sprintf('table [%s] has none of [%s]', $table, implode(', ', $candidates));

                return $report;
            }

            /** @var list<string> $contextCandidates */
            $contextCandidates = $surface['context'];

            $context = array_values(array_filter(
                $contextCandidates,
                static fn (string $column): bool => $schema->hasColumn($table, $column),
            ));

            $select = array_values(array_unique(array_merge([$keyName], $context, $columns)));

            $query = DB::connection($connectionName)->table($table)->select($select);
            $this->applyFilter($query, $surface, $columns);

            // Keyset paging, and nothing is written, so the result set cannot
            // shift under the pager because of this command.
            $query->chunkById(
                self::CHUNK_SIZE,
                function (Collection $rows) use (&$report, $surface, $keyName, $columns): void {
                    $this->classifyRows($rows, $report, $surface, $keyName, $columns);
                },
                $keyName,
            );
        } catch (Throwable $e) {
            // Partial counts are KEPT and reported: they are still evidence,
            // and the status is what stops them from being read as a clean bill
            // of health.
            $report['status'] = 'error';
            $report['detail'] = $e->getMessage();
        }

        return $report;
    }

    /**
     * Classify every value in a page. Pure reporting — nothing is written and
     * no plaintext is retained beyond the decrypt attempt.
     *
     * @param  Collection<int, object>  $rows
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $surface
     * @param  list<string>  $columns
     */
    private function classifyRows(Collection $rows, array &$report, array $surface, string $keyName, array $columns): void
    {
        foreach ($rows as $row) {
            $report['scanned']++;

            foreach ($columns as $column) {
                $raw = $row->{$column} ?? null;

                // Genuinely absent: the two-factor filter is an OR, so a row
                // with only one of the two columns set is expected. Not a
                // finding, not counted.
                if ($raw === null) {
                    continue;
                }

                // Present but not a usable ciphertext (empty string, or a
                // non-string a custom cast produced). No key can read it, so it
                // is reported exactly like any other unreadable value — the
                // same rule `encryption:rekey` applies, so the two commands can
                // never disagree about whether the data is clean.
                if (! is_string($raw) || trim($raw) === '') {
                    $report['unreadable']++;
                    $this->recordIdentifier($report, $surface, $row, $keyName, $column);

                    continue;
                }

                $index = $this->attribute($raw);

                if ($index === null) {
                    $report['unreadable']++;
                    $this->recordIdentifier($report, $surface, $row, $keyName, $column);

                    continue;
                }

                if ($index === 0) {
                    $report['primary']++;

                    continue;
                }

                $report['previous']++;
                $source = $this->keySources[$index];
                $report['by_source'][$source] = ($report['by_source'][$source] ?? 0) + 1;
            }
        }
    }

    /**
     * Index of the FIRST chain key that can read this ciphertext, or null when
     * none can.
     *
     * The plaintext is discarded immediately; it is never returned, printed,
     * counted by length or compared.
     */
    private function attribute(string $cipherText): ?int
    {
        foreach ($this->encrypters as $index => $encrypter) {
            try {
                // DecryptException specifically, never Throwable: a bad MAC, a
                // wrong key and a malformed payload all raise it, while a real
                // bug must surface loudly instead of being misreported as data
                // that needs a key nobody has.
                $encrypter->decryptString($cipherText);
            } catch (DecryptException) {
                continue;
            }

            return $index;
        }

        return null;
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
     * Remember which value nothing could read, capped so a fully orphaned table
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
     * Roll the per-surface reports up.
     *
     * `unvouched` and `config_block_missing` come from the COVERAGE probe, not
     * from any row: a surface whose serving encrypter is not the kit's, and a
     * config block that hides DATA_ENCRYPTION_KEY entirely, both make the
     * row-level attribution below a true statement about the wrong thing.
     *
     * @param  list<array<string, mixed>>  $surfaces
     * @param  list<array{surface: string, status: string, encrypter: string, kit_built: bool, detail: string}>  $coverage
     * @param  array{present: bool, configuration_cached: bool, primary_key_in_environment: bool}  $configBlock
     * @return array{scanned: int, primary: int, previous: int, unreadable: int, incomplete: int, unvouched: int, config_block_missing: bool}
     */
    private function summarize(array $surfaces, array $coverage, array $configBlock): array
    {
        $summary = [
            'scanned' => 0,
            'primary' => 0,
            'previous' => 0,
            'unreadable' => 0,
            'incomplete' => 0,
            'unvouched' => 0,
            'config_block_missing' => ! $configBlock['present'],
        ];

        foreach ($coverage as $entry) {
            if (EncrypterCoverage::isNotVouched($entry['status'])) {
                $summary['unvouched']++;
            }
        }

        foreach ($surfaces as $surface) {
            $summary['scanned'] += $surface['scanned'];
            $summary['primary'] += $surface['primary'];
            $summary['previous'] += $surface['previous'];
            $summary['unreadable'] += $surface['unreadable'];

            if ($surface['status'] !== 'ok') {
                $summary['incomplete']++;
            }
        }

        return $summary;
    }

    /**
     * The verdict, in strict precedence order.
     *
     * Unreadable first because it is the only state where data is ALREADY out
     * of reach. Then coverage: a surface served by an encrypter that is not the
     * kit's, or a config block that makes the configured key inert, means the
     * attribution below describes something other than this install's real
     * read/write path — reporting "rekey required" from it would send the
     * operator to a command that is about to refuse. Then rows on an old key,
     * which clearing the list would put out of reach; then an incomplete scan,
     * which cannot prove either way. "Safe" is reachable only when every
     * surface was scanned, vouched for, and every value it held read with the
     * primary key.
     *
     * @param  array{scanned: int, primary: int, previous: int, unreadable: int, incomplete: int, unvouched: int, config_block_missing: bool}  $summary
     */
    private function decideVerdict(array $summary): string
    {
        if ($summary['unreadable'] > 0) {
            return self::VERDICT_UNREADABLE;
        }

        if ($summary['unvouched'] > 0 || $summary['config_block_missing']) {
            return self::VERDICT_NOT_COVERED;
        }

        if ($summary['previous'] > 0) {
            return self::VERDICT_REKEY_REQUIRED;
        }

        if ($summary['incomplete'] > 0) {
            return self::VERDICT_INCOMPLETE;
        }

        return self::VERDICT_SAFE;
    }

    private function exitCodeFor(string $verdict): int
    {
        return match ($verdict) {
            self::VERDICT_SAFE => self::SUCCESS,
            self::VERDICT_UNREADABLE, self::VERDICT_KEY_ERROR => self::EXIT_BROKEN,
            default => self::EXIT_NOT_CLEAN,
        };
    }

    /**
     * One-line summary of the verdict, shared by both output modes so the JSON
     * consumer and the operator read the same sentence.
     *
     * @param  array{scanned: int, primary: int, previous: int, unreadable: int, incomplete: int, unvouched: int, config_block_missing: bool}  $summary
     */
    private function verdictMessage(string $verdict, array $summary): string
    {
        return match ($verdict) {
            self::VERDICT_SAFE => sprintf(
                'Safe to clear %s. All %d scanned value(s) decrypt with the primary key alone.',
                DataEncrypterFactory::PREVIOUS_ENV_KEY,
                $summary['primary'],
            ),
            self::VERDICT_REKEY_REQUIRED => sprintf(
                '%d value(s) still decrypt only with a non-primary key. Run `php artisan encryption:rekey` first; '
                .'do NOT clear %s.',
                $summary['previous'],
                DataEncrypterFactory::PREVIOUS_ENV_KEY,
            ),
            self::VERDICT_UNREADABLE => sprintf(
                '%d value(s) cannot be read by ANY configured key. The key that wrote them is missing from .env — '
                .'ADD it to %s. Do NOT clear that list: a key removed from the chain cannot be recovered, and the '
                .'ciphertext is still intact on disk until then.',
                $summary['unreadable'],
                DataEncrypterFactory::PREVIOUS_ENV_KEY,
            ),
            self::VERDICT_NOT_COVERED => $summary['config_block_missing']
                ? sprintf(
                    'The `starter-kit.encryption` config block is absent, so %s cannot take effect no matter what '
                    .'.env says and the primary key silently falls back to %s. Fix the configuration before '
                    .'reading anything below as a verdict.',
                    DataEncrypterFactory::PRIMARY_ENV_KEY,
                    DataEncrypterFactory::APP_ENV_KEY,
                )
                : sprintf(
                    '%d surface(s) are served by an encrypter this kit did not build or could not inspect, so this '
                    .'report does not describe how they are actually read and written. Do NOT clear %s on the '
                    .'strength of it.',
                    $summary['unvouched'],
                    DataEncrypterFactory::PREVIOUS_ENV_KEY,
                ),
            self::VERDICT_INCOMPLETE => sprintf(
                '%d surface(s) could not be fully scanned, so this run cannot vouch for them. Every value it DID '
                .'read is on the primary key. Do NOT clear %s on the strength of this report.',
                $summary['incomplete'],
                DataEncrypterFactory::PREVIOUS_ENV_KEY,
            ),
            default => 'The encryption key chain could not be resolved, so nothing was attributed.',
        };
    }

    /**
     * @param  array<string, mixed>  $keys
     * @param  list<array<string, mixed>>  $surfaces
     * @param  array{scanned: int, primary: int, previous: int, unreadable: int, incomplete: int, unvouched: int, config_block_missing: bool}  $summary
     * @param  list<array{surface: string, status: string, encrypter: string, kit_built: bool, detail: string}>  $coverage
     * @param  array{present: bool, configuration_cached: bool, primary_key_in_environment: bool}  $configBlock
     */
    private function outputText(array $keys, array $surfaces, array $summary, string $verdict, array $coverage, array $configBlock): int
    {
        $this->newLine();
        $this->line('  <fg=blue;options=bold>Starter Kit — Data Encryption Health</>');
        $this->newLine();

        $this->line(sprintf('  Primary key : %s (cipher %s)', $keys['source'], $keys['cipher']));
        $this->line(sprintf('  Read chain  : %s', implode(' -> ', $keys['chain'])));
        $this->line(sprintf(
            '  Previous    : %d key(s) from %s in the chain%s%s',
            $keys['previous_keys_in_chain'],
            DataEncrypterFactory::PREVIOUS_ENV_KEY,
            $keys['previous_keys_env_set'] && $keys['previous_keys_in_chain'] === 0
                ? ' (the env var is set, but every entry was blank or a duplicate of another key)'
                : '',
            $keys['app_previous_keys_in_chain'] > 0
                ? sprintf(', plus %d inherited from %s', $keys['app_previous_keys_in_chain'], DataEncrypterFactory::APP_PREVIOUS_ENV_KEY)
                : '',
        ));
        $this->line(sprintf(
            '  %s     : %s',
            DataEncrypterFactory::APP_ENV_KEY,
            $keys['app_key_in_chain']
                ? ($keys['app_key_chain_source'] === DataEncrypterFactory::APP_ENV_KEY
                    ? 'in the read chain'
                    : sprintf('in the read chain (as %s)', $keys['app_key_chain_source']))
                : 'NOT in the read chain',
        ));

        if (! $keys['using_dedicated_key']) {
            $this->newLine();
            $this->warn(sprintf(
                '  No %s is configured, so this data is encrypted with %s. One `php artisan key:generate` '
                .'makes all of it unreadable — run `php artisan encryption:key` to adopt a dedicated key.',
                DataEncrypterFactory::PRIMARY_ENV_KEY,
                DataEncrypterFactory::APP_ENV_KEY,
            ));
        }

        $this->reportConfigBlock($configBlock);

        $this->newLine();
        $this->line('  <options=bold>Who serves each surface</>');

        foreach ($coverage as $entry) {
            $this->reportCoverageEntry($entry);
        }

        $this->newLine();
        $this->line('  <options=bold>What each surface stores</>');

        foreach ($surfaces as $surface) {
            if ($surface['status'] !== 'ok') {
                $this->warn(sprintf('  %-11s NOT SCANNED — %s.', $surface['name'], $surface['detail']));

                continue;
            }

            $this->line(sprintf(
                '  %-11s %d row(s) scanned — %d on the primary key, %d on a previous key%s, %d unreadable.',
                $surface['name'],
                $surface['scanned'],
                $surface['primary'],
                $surface['previous'],
                $this->formatSources($surface['by_source']),
                $surface['unreadable'],
            ));

            if ($surface['identifiers'] !== []) {
                foreach ($surface['identifiers'] as $identifier) {
                    $this->line('    <fg=red>unreadable:</> '.$identifier);
                }

                if ($surface['overflow'] > 0) {
                    $this->line(sprintf('    ... and %d more (list capped at %d).', $surface['overflow'], self::MAX_LISTED_IDENTIFIERS));
                }
            }
        }

        $this->newLine();

        $message = $this->verdictMessage($verdict, $summary);

        match ($verdict) {
            self::VERDICT_SAFE => $this->info('  '.$message),
            self::VERDICT_UNREADABLE => $this->error('  '.$message),
            default => $this->warn('  '.$message),
        };

        $this->newLine();

        return $this->exitCodeFor($verdict);
    }

    /**
     * Machine-readable report, shaped like `sk:doctor --json` (version,
     * generated_at, summary, then the per-item list) so one consumer can read
     * both.
     *
     * @param  array<string, mixed>  $keys
     * @param  list<array<string, mixed>>  $surfaces
     * @param  array{scanned: int, primary: int, previous: int, unreadable: int, incomplete: int, unvouched: int, config_block_missing: bool}  $summary
     * @param  list<array{surface: string, status: string, encrypter: string, kit_built: bool, detail: string}>  $coverage
     * @param  array{present: bool, configuration_cached: bool, primary_key_in_environment: bool}  $configBlock
     */
    private function outputJson(array $keys, array $surfaces, array $summary, string $verdict, array $coverage, array $configBlock): int
    {
        $exitCode = $this->exitCodeFor($verdict);

        $this->line((string) json_encode([
            'version' => 1,
            'generated_at' => now()->toISOString(),
            'verdict' => $verdict,
            'safe_to_clear' => $verdict === self::VERDICT_SAFE,
            'exit_code' => $exitCode,
            'message' => $this->verdictMessage($verdict, $summary),
            'keys' => $keys,
            'config_block' => $configBlock,
            'coverage' => $coverage,
            'summary' => $summary,
            'surfaces' => array_map(static fn (array $surface): array => [
                'name' => $surface['name'],
                'table' => $surface['table'],
                'status' => $surface['status'],
                'detail' => $surface['detail'],
                'scanned' => $surface['scanned'],
                'primary' => $surface['primary'],
                'previous' => $surface['previous'],
                'unreadable' => $surface['unreadable'],
                'by_source' => (object) $surface['by_source'],
                'identifiers' => $surface['identifiers'],
                'overflow' => $surface['overflow'],
            ], $surfaces),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $exitCode;
    }

    /**
     * Report a chain that does not resolve at all.
     *
     * Loudest exit, and no surface is scanned: with no usable key every row
     * would be classified "unreadable", which is a true statement that would
     * mislead — the data is fine, the configuration is not.
     */
    private function reportKeyError(string $message, bool $json): int
    {
        $verdict = self::VERDICT_KEY_ERROR;
        $summary = [
            'scanned' => 0,
            'primary' => 0,
            'previous' => 0,
            'unreadable' => 0,
            'incomplete' => 0,
            'unvouched' => 0,
            'config_block_missing' => false,
        ];

        if ($json) {
            $this->line((string) json_encode([
                'version' => 1,
                'generated_at' => now()->toISOString(),
                'verdict' => $verdict,
                'safe_to_clear' => false,
                'exit_code' => self::EXIT_BROKEN,
                'message' => $message,
                'keys' => null,
                'config_block' => null,
                'coverage' => [],
                'summary' => $summary,
                'surfaces' => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::EXIT_BROKEN;
        }

        $this->error('Encryption keys could not be resolved, so no value could be attributed to a key.');
        $this->error($message);
        $this->warn(sprintf(
            'Fix the configuration before touching any key. Do NOT clear %s: the stored ciphertext is intact, '
            .'and a key removed from that list cannot be recovered.',
            DataEncrypterFactory::PREVIOUS_ENV_KEY,
        ));

        return self::EXIT_BROKEN;
    }

    /**
     * Warn when the `starter-kit.encryption` config block is not there at all.
     *
     * This is the one failure mode the row scan below cannot see. With the
     * block absent every key under it reads null, so the factory takes the
     * APP_KEY path and every row legitimately attributes to APP_KEY — a clean
     * report, produced by a broken configuration, on an install whose operator
     * set DATA_ENCRYPTION_KEY and believes their data survives `key:generate`.
     *
     * @param  array{present: bool, configuration_cached: bool, primary_key_in_environment: bool}  $configBlock
     */
    private function reportConfigBlock(array $configBlock): void
    {
        if ($configBlock['present']) {
            return;
        }

        $this->newLine();
        $this->error(sprintf(
            '  The `starter-kit.encryption` config block is ABSENT, so %s, %s and %s all read null and the primary '
            .'key falls back to %s%s.',
            DataEncrypterFactory::PRIMARY_ENV_KEY,
            DataEncrypterFactory::PREVIOUS_ENV_KEY,
            DataEncrypterFactory::CIPHER_ENV_KEY,
            DataEncrypterFactory::APP_ENV_KEY,
            $configBlock['primary_key_in_environment']
                ? sprintf(' — and %s IS set in this environment, so it is currently inert', DataEncrypterFactory::PRIMARY_ENV_KEY)
                : '',
        ));
        $this->warn(sprintf(
            '  Cause: a published config/starter-kit.php that predates the encryption release%s. '
            .'Fix: re-publish it with `php artisan vendor:publish --tag=starter-kit-config --force`%s, then run this command '
            .'again. Everything below describes the %s fallback, not %s.',
            $configBlock['configuration_cached']
                ? ', combined with a cached config (config:cache makes the package default merge a no-op)'
                : '',
            $configBlock['configuration_cached'] ? ' and `php artisan config:cache` again' : '',
            DataEncrypterFactory::APP_ENV_KEY,
            DataEncrypterFactory::PRIMARY_ENV_KEY,
        ));
    }

    /**
     * One line per surface naming the encrypter that actually serves it.
     *
     * Printed BEFORE the row attribution on purpose: an operator who reads
     * "42 rows on the primary key" first, and only then learns the surface is
     * served by someone else's encrypter, has already formed the wrong
     * conclusion.
     *
     * @param  array{surface: string, status: string, encrypter: string, kit_built: bool, detail: string}  $entry
     */
    private function reportCoverageEntry(array $entry): void
    {
        $line = sprintf('  %-11s %s', $entry['surface'], $entry['detail']);

        match ($entry['status']) {
            EncrypterCoverage::STATUS_COVERED => $this->line($line),
            EncrypterCoverage::STATUS_NO_WRITER => $this->line($line),
            EncrypterCoverage::STATUS_FOREIGN => $this->error($line),
            default => $this->warn($line),
        };

        if (! $entry['kit_built'] && EncrypterCoverage::isNotVouched($entry['status'])) {
            $this->warn(sprintf(
                '    The kit did not build this encrypter [%s] and will not rebind it. Nothing below is a claim '
                .'about this surface.',
                $entry['encrypter'],
            ));
        }
    }

    /**
     * Render the per-key breakdown of values riding an old key.
     *
     * Prints the `source` LABEL of each chain entry, which
     * {@see DataEncrypterFactory::keys()} designates as the only printable
     * member.
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

        return ' ('.implode(', ', $parts).')';
    }

    /**
     * The surfaces this command knows how to attribute.
     *
     * Kept in step with {@see EncryptionRekeyCommand::surfaces()} — see the
     * class docblock for why the duplication is deliberate.
     *
     * @return list<array<string, mixed>>
     */
    private function surfaces(): array
    {
        [$settingsConnection, $settingsTable, $settingsKey] = $this->resolveTarget('App\\Models\\Setting', 'settings', 'id');
        [$userConnection, $userTable, $userKey] = $this->resolveTarget($this->userModel(), 'users', 'id');

        return [
            [
                'name' => EncryptionRekeyCommand::SURFACE_SETTINGS,
                'connection' => $settingsConnection,
                'table' => $settingsTable,
                'key' => $settingsKey,
                'columns' => ['value'],
                // Without the flag column there is no way to tell an encrypted
                // row from a plaintext one, and guessing would report the whole
                // table as unreadable — the loudest verdict, raised by a schema
                // shape rather than by data. Report it as unscannable instead.
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
                'name' => EncryptionRekeyCommand::SURFACE_TWO_FACTOR,
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
     * A consumer that repointed a model at another table or another connection
     * must have THAT one scanned, and the key NAME has to come from the model
     * too, since the scan pages by it.
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
}
