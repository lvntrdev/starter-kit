<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;
use Lvntr\StarterKit\Support\Encryption\DataEncrypterFactory;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Generates the dedicated DATA_ENCRYPTION_KEY and preserves the key it
 * replaces. This is the ONLY code path in the kit that writes key material
 * into `.env`.
 *
 * ## The safety property is the WRITE ORDER, not the key generation
 *
 * Generating a random key is trivial; not losing the old one is not. The
 * command executes in exactly this order, and the order is the whole feature:
 *
 *   1. read `.env` and resolve the CURRENT primary key — DATA_ENCRYPTION_KEY,
 *      or APP_KEY when the dedicated key is absent (first adoption);
 *   2. generate a new random key for the configured cipher (in memory only);
 *   3. write DATA_ENCRYPTION_PREVIOUS_KEYS with the current primary PREPENDED
 *      to the existing list, and flush that to disk;
 *   4. only THEN write DATA_ENCRYPTION_KEY.
 *
 * A crash between 3 and 4 leaves an `.env` whose primary is still the old key
 * and whose previous-list also names it — redundant, deduplicated by
 * {@see DataEncrypterFactory}, and fully readable. The reverse order would
 * leave a tree whose primary is a key nothing was ever encrypted with and
 * whose previous-list is empty: every encrypted settings row and every 2FA
 * secret unreadable, with no copy of the old key anywhere. Each step is an
 * atomic write ({@see self::putEnvPreservingIdentity()}), so a reader never
 * observes a truncated `.env` either — and that write deliberately preserves
 * the file's symlink target and permission bits, which a plain temp-file
 * rename would silently discard.
 *
 * ## APP_KEY is never touched, on any path
 *
 * Not read-modified, not re-emitted, not reformatted. Two independent
 * mechanisms enforce it: {@see self::setEnvValue()} is only ever called with
 * the two DATA_ENCRYPTION_* keys, and {@see self::assertAppKeyUntouched()}
 * re-checks every candidate `.env` body BEFORE it reaches disk and aborts
 * without writing if any APP_KEY line differs. The second check exists because
 * a regex that accidentally widened its match would otherwise destroy the
 * fallback key that keeps pre-adoption rows readable.
 *
 * ## Secret handling
 *
 * The new key is written to `.env` and NOT echoed — a rotation does not need
 * it on screen, and the terminal scrollback is a worse place for it than the
 * file. The OLD key is never printed, never logged, and appears in output only
 * as the NAME of the env var it came from. `--show` is the single sanctioned
 * printing path: it emits one freshly generated key on stdout and writes
 * nothing at all.
 *
 * Nothing here writes to the logger. Values that reach an exception message do
 * so through {@see DataEncrypterFactory::parseKey()}, which withholds key
 * material by design.
 */
final class EncryptionKeyCommand extends Command
{
    /**
     * Characters an `.env` value may carry verbatim.
     *
     * Anything outside this set is re-encoded before it is written (see
     * {@see self::envSafeValue()}). The set is deliberately narrow: a comma
     * would split the previous-key list, a hash would start an inline comment,
     * a dollar sign would trigger phpdotenv variable interpolation, and
     * whitespace or a newline would truncate or corrupt the line. A `base64:`
     * prefixed key and a raw ASCII key both pass unchanged, which covers every
     * real install.
     */
    private const ENV_SAFE_VALUE = '%^[A-Za-z0-9+/=:_.\-]+$%';

    /**
     * Marker line used to detect an already-appended block, so a run that has
     * to append BOTH keys does not emit the header twice.
     *
     * Must stay byte-identical to the first line of self::BLOCK_COMMENT.
     */
    private const BLOCK_HEADER = '# ---- Encryption ----';

    /**
     * Comment header appended when the keys are absent from `.env` entirely.
     *
     * Deliberately does NOT contain the literal APP_KEY: assertAppKeyUntouched()
     * compares APP_KEY assignment lines, and prose is not one, but keeping the
     * token out of generated content removes the question entirely.
     */
    private const BLOCK_COMMENT = <<<'TXT'
        # ---- Encryption ----
        # Dedicated key for sensitive settings (settings.value) and 2FA secrets.
        # `php artisan key:generate` neither creates NOR overwrites this key; it must be
        # carried together with .env on a server migration.
        # DATA_ENCRYPTION_PREVIOUS_KEYS is cleared ONLY after `encryption:rekey` finishes
        # and `encryption:health` reports OK. A key dropped from that list is unrecoverable.
        TXT;

    protected $signature = 'encryption:key
        {--show : Print a freshly generated key and write nothing}
        {--force : Run even when the environment looks like production}';

    protected $description = 'Generate a dedicated DATA_ENCRYPTION_KEY, preserving the current key in DATA_ENCRYPTION_PREVIOUS_KEYS.';

    private Filesystem $files;

    private DataEncrypterFactory $factory;

    /**
     * Both dependencies are optional so the command can be constructed bare in
     * a test (`new EncryptionKeyCommand`) exactly like InstallCommand is, while
     * still accepting container-resolved instances.
     */
    public function __construct(?Filesystem $files = null, ?DataEncrypterFactory $factory = null)
    {
        parent::__construct();

        $this->files = $files ?? new Filesystem;
        $this->factory = $factory ?? new DataEncrypterFactory;
    }

    public function handle(): int
    {
        try {
            $cipher = $this->validatedCipher();
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return Command::FAILURE;
        }

        // --show mutates nothing, so the production guard below does not apply
        // to it. Requiring --force here would push an operator who only wants a
        // key to copy by hand onto the writing path instead — the opposite of
        // the intent. It stays unguarded on purpose.
        if ($this->option('show')) {
            $this->line($this->generateKey($cipher));

            return Command::SUCCESS;
        }

        if ($this->isProductionLikeEnvironment() && ! $this->option('force')) {
            $this->components->error(
                'This environment looks like production. Rotating the data encryption key here '
                .'makes every encrypted value unreadable until `encryption:rekey` has run. '
                .'Re-run with --force once you have a database backup and a maintenance window.'
            );

            return Command::FAILURE;
        }

        $envPath = base_path('.env');

        if (! $this->files->exists($envPath)) {
            $this->components->error(
                "No .env file at [{$envPath}]. This command rotates an existing environment file; "
                .'it never creates one. Run `php artisan sk:install` first.'
            );

            return Command::FAILURE;
        }

        try {
            $this->rotate($envPath, $cipher);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Perform the rotation in the documented order. See the class docblock —
     * the ordering of the two writes below is the safety property of the whole
     * command; do not merge them into one write and do not swap them.
     *
     * @throws RuntimeException
     */
    private function rotate(string $envPath, string $cipher): void
    {
        // (1) Resolve the current primary from the FILE, not from config().
        // A cached config (`config:cache`) can disagree with .env, and the key
        // that must be preserved is the one the file will keep feeding the app.
        $initial = $this->files->get($envPath);

        [$currentPrimary, $primarySource] = $this->currentPrimary($initial);

        // (2) Generate in memory. Nothing is on disk yet.
        $newKey = $this->generateKey($cipher);

        $content = $initial;

        if ($currentPrimary === null) {
            // Neither a dedicated key nor an APP_KEY: there is no prior key, so
            // there is nothing to preserve and no encrypted data that could be
            // orphaned. Skip step (3) rather than writing an empty list.
            $this->components->warn(
                'No existing encryption key was found in .env, so nothing was added to '
                .DataEncrypterFactory::PREVIOUS_ENV_KEY.'. If this app already stores encrypted '
                .'values, STOP and restore the key that wrote them before continuing.'
            );
        } else {
            // (3) Previous list FIRST.
            $content = $this->setEnvValue(
                $content,
                DataEncrypterFactory::PREVIOUS_ENV_KEY,
                implode(',', $this->prependPreviousKey($initial, $currentPrimary, $primarySource)),
            );

            $this->assertAppKeyUntouched($initial, $content);
            $this->putEnvPreservingIdentity($envPath, $content);
        }

        // (4) New primary LAST.
        $content = $this->setEnvValue($content, DataEncrypterFactory::PRIMARY_ENV_KEY, $newKey);

        $this->assertAppKeyUntouched($initial, $content);
        $this->putEnvPreservingIdentity($envPath, $content);

        $this->report($primarySource);
    }

    /**
     * Atomic `.env` write that preserves the file's IDENTITY — its symlink
     * target and its permission bits.
     *
     * The generic {@see WritesFilesAtomically::atomicPut()} is wrong for this
     * one file, in two ways that both end in the loss this command exists to
     * prevent:
     *
     * 1. **Symlinked `.env`.** Envoyer / Deployer / Capistrano layouts keep the
     *    real `.env` in a shared directory and symlink it into each release.
     *    `rename()` replaces the LINK with a regular file, so the new key lands
     *    in a release directory that the next deploy discards. The operator
     *    then runs `encryption:rekey`, every row moves onto that key, and the
     *    key disappears with the release — exactly the orphaned-data failure
     *    the write order in {@see self::rotate()} was built to make impossible.
     * 2. **Permission widening.** `rename()` carries the temp file's mode, so a
     *    hardened `0600` `.env` comes back as `0644` (umask-dependent) — and
     *    the temp file itself was world-readable WHILE holding the new key.
     *
     * So: resolve the link first and write onto its target; capture the
     * target's mode and restore it before the rename; and narrow the temp file
     * to `0600` while it is still EMPTY, so key material never exists in a
     * wide-mode file. Atomicity is kept — the temp file stays in the target's
     * own directory, so the rename never crosses a filesystem.
     */
    private function putEnvPreservingIdentity(string $path, string $contents): void
    {
        // Follow the link BEFORE anything else: every decision below (mode,
        // temp directory, rename target) must be about the real file.
        $target = is_link($path) ? (realpath($path) ?: $path) : $path;

        $mode = file_exists($target) ? (fileperms($target) & 0777) : 0600;

        $dir = dirname($target);
        $temp = $dir.DIRECTORY_SEPARATOR.'.'.basename($target).'.tmp'.bin2hex(random_bytes(6));

        try {
            // Create empty, narrow immediately, and only then write: the window
            // in which the file is world-readable never contains a key. The
            // content write goes through $this->files so it stays observable to
            // an injected Filesystem (the write-ORDER assertions depend on it);
            // file_put_contents does not reset an existing file's mode, so the
            // 0600 taken here holds while the key is on disk.
            if (! @touch($temp)) {
                throw new RuntimeException("Could not create a temporary file next to [{$target}].");
            }

            @chmod($temp, 0600);

            $this->files->put($temp, $contents);

            @chmod($temp, $mode);

            if (! @rename($temp, $target)) {
                throw new RuntimeException("Atomic write failed: could not move temp file into place for [{$target}].");
            }
        } catch (Throwable $e) {
            if ($this->files->exists($temp)) {
                $this->files->delete($temp);
            }

            throw $e;
        }
    }

    /**
     * The key the app is encrypting with right now, and the env var it came
     * from. Mirrors DataEncrypterFactory's primary-key contract: the dedicated
     * key when set, otherwise APP_KEY (first adoption).
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function currentPrimary(string $content): array
    {
        $dedicated = $this->readEnvValue($content, DataEncrypterFactory::PRIMARY_ENV_KEY);

        if ($dedicated !== null) {
            return [$dedicated, DataEncrypterFactory::PRIMARY_ENV_KEY];
        }

        $appKey = $this->readEnvValue($content, DataEncrypterFactory::APP_ENV_KEY);

        if ($appKey !== null) {
            return [$appKey, DataEncrypterFactory::APP_ENV_KEY];
        }

        return [null, null];
    }

    /**
     * Current primary prepended to the existing previous-key list.
     *
     * Order is preserved (newest retired key first, so the most likely decrypt
     * hit is tried earliest), blanks are dropped and exact duplicates removed —
     * the same normalisation DataEncrypterFactory::previousKeyValues() applies,
     * so a re-run does not grow the list without bound. Keys that differ only
     * in encoding are collapsed by the factory at read time, on decoded
     * material; this list is intentionally not smart about that, because
     * dropping an entry here is irreversible and dropping one there is not.
     *
     * @return list<string>
     */
    private function prependPreviousKey(string $content, #[SensitiveParameter] string $currentPrimary, string $source): array
    {
        $list = [$this->envSafeValue($currentPrimary, $source)];

        foreach ($this->existingPreviousKeys($content) as $entry) {
            if (! in_array($entry, $list, true)) {
                $list[] = $entry;
            }
        }

        return $list;
    }

    /**
     * Existing DATA_ENCRYPTION_PREVIOUS_KEYS entries, trimmed, blanks dropped.
     *
     * @return list<string>
     */
    private function existingPreviousKeys(string $content): array
    {
        $raw = $this->readEnvValue($content, DataEncrypterFactory::PREVIOUS_ENV_KEY);

        if ($raw === null) {
            return [];
        }

        $values = [];

        foreach (explode(',', $raw) as $item) {
            $item = trim($item);

            if ($item !== '' && ! in_array($item, $values, true)) {
                $values[] = $item;
            }
        }

        return $values;
    }

    /**
     * A key value that can live on an `.env` line without changing meaning.
     *
     * A `base64:` key and a raw ASCII key are returned VERBATIM — re-encoding a
     * value that already works is a needless way to break it. Only a value
     * carrying a character that `.env` parsing would mangle (a raw binary
     * APP_KEY is legal in Laravel and would corrupt the line) is re-emitted as
     * `base64:`, which DataEncrypterFactory::parseKey() decodes back to the
     * identical bytes. The decode runs before any write, so a malformed key
     * aborts the command instead of half-rotating it.
     *
     * @throws RuntimeException naming the env var, never its value
     */
    private function envSafeValue(#[SensitiveParameter] string $value, string $source): string
    {
        if (preg_match(self::ENV_SAFE_VALUE, $value) === 1) {
            return $value;
        }

        return 'base64:'.base64_encode($this->factory->parseKey($value, $source));
    }

    /**
     * Read one key's effective value out of an `.env` body.
     *
     * Commented lines are ignored, `export ` is tolerated and surrounding
     * quotes are stripped. A blank value reads as null, matching the factory's
     * treatment of the shipped `DATA_ENCRYPTION_KEY=` placeholder.
     */
    private function readEnvValue(string $content, string $key): ?string
    {
        if (! preg_match_all($this->assignmentPattern($key), $content, $matches)) {
            return null;
        }

        // Duplicate assignments: Laravel builds an IMMUTABLE dotenv repository,
        // but that only protects variables defined OUTSIDE the file --
        // ImmutableWriter::isExternallyDefined() stops reporting a name once it
        // is in its own $loaded set, so a later line in the SAME .env does
        // overwrite an earlier one. The LAST assignment is therefore what the
        // running app reads, and preserving anything else here would save a key
        // the data was not encrypted with.
        $value = trim((string) end($matches[1]));

        if (strlen($value) >= 2) {
            $quote = $value[0];

            if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
                $value = substr($value, 1, -1);
            }
        }

        return $value === '' ? null : $value;
    }

    /**
     * Set a key in an `.env` body, preserving surrounding lines, comments and
     * key order.
     *
     * Every existing assignment of the key is rewritten, not just the first: a
     * file with duplicates would otherwise keep a stale line that phpdotenv
     * prefers over the one we wrote. A commented-out placeholder is filled in
     * instead of being duplicated (the pattern InstallCommand::ensureCachePrefix
     * uses); only when the key is absent altogether is a block appended.
     *
     * The replacement goes through preg_replace_callback so no character in key
     * material can ever be interpreted as a backreference.
     */
    private function setEnvValue(string $content, string $key, #[SensitiveParameter] string $value): string
    {
        $line = $key.'='.$value;

        $assignment = $this->assignmentPattern($key);

        if (preg_match($assignment, $content) === 1) {
            return (string) preg_replace_callback($assignment, static fn (): string => $line, $content);
        }

        $commented = '%^[ \t]*#[ \t]*(?:export[ \t]+)?'.preg_quote($key, '%').'[ \t]*=.*$%m';

        if (preg_match($commented, $content) === 1) {
            return (string) preg_replace_callback($commented, static fn (): string => $line, $content, 1);
        }

        $prefix = str_contains($content, self::BLOCK_HEADER)
            ? "\n"
            : "\n\n".self::BLOCK_COMMENT."\n";

        return rtrim($content, "\n").$prefix.$line."\n";
    }

    /**
     * Pattern matching an uncommented assignment of $key, capturing its value.
     */
    private function assignmentPattern(string $key): string
    {
        return '%^[ \t]*(?:export[ \t]+)?'.preg_quote($key, '%').'[ \t]*=(.*)$%m';
    }

    /**
     * Fail closed if a rewrite would have altered an APP_KEY line.
     *
     * Called on the candidate body BEFORE it is written, so tripping this
     * leaves `.env` untouched. APP_KEY is the fallback that keeps every row
     * written before adoption readable; changing it here would be silent,
     * irreversible data loss.
     *
     * @throws RuntimeException
     */
    private function assertAppKeyUntouched(string $before, string $after): void
    {
        if ($this->appKeyLines($before) !== $this->appKeyLines($after)) {
            throw new RuntimeException(
                'Refusing to write .env: the rewrite would have modified an '
                .DataEncrypterFactory::APP_ENV_KEY.' line. Nothing was written. '
                .'This is a bug in encryption:key — it must never touch that key.'
            );
        }
    }

    /**
     * Every APP_KEY assignment line, commented ones included, verbatim.
     *
     * Scoped to assignments so that prose mentioning the key name cannot trip
     * the guard.
     *
     * @return list<string>
     */
    private function appKeyLines(string $content): array
    {
        preg_match_all(
            '%^[ \t]*#?[ \t]*(?:export[ \t]+)?'.preg_quote(DataEncrypterFactory::APP_ENV_KEY, '%').'[ \t]*=.*$%m',
            $content,
            $matches,
        );

        return $matches[0];
    }

    /**
     * The cipher the new key must fit, proven usable before anything is
     * generated.
     *
     * Encrypter::generateKey() silently falls back to 32 bytes for a cipher it
     * does not know, which would write a key that every later read rejects. The
     * probe below uses NUL bytes so no real key material touches this path.
     *
     * @throws RuntimeException
     */
    private function validatedCipher(): string
    {
        $cipher = $this->factory->cipher();

        foreach ([16, 32] as $length) {
            if (Encrypter::supported(str_repeat("\0", $length), $cipher)) {
                return $cipher;
            }
        }

        throw new RuntimeException(sprintf(
            'Cipher [%s] is not supported, so no key can be generated for it. Fix %s (or app.cipher) '
            .'in .env before rotating — nothing was written.',
            $cipher,
            DataEncrypterFactory::CIPHER_ENV_KEY,
        ));
    }

    /**
     * A fresh key in the `base64:` form the rest of the kit expects.
     */
    private function generateKey(string $cipher): string
    {
        return 'base64:'.base64_encode(Encrypter::generateKey($cipher));
    }

    /**
     * The mandatory next steps. Clearing the previous-key list before
     * `encryption:health` reports OK is the one operator action in this feature
     * that destroys data, so it is spelled out every run.
     */
    private function report(?string $primarySource): void
    {
        $this->newLine();
        $this->components->info('A new '.DataEncrypterFactory::PRIMARY_ENV_KEY.' was written to .env.');

        if ($primarySource !== null) {
            $this->components->twoColumnDetail('Previous key preserved from', '<fg=green>'.$primarySource.'</>');
        }

        $this->components->twoColumnDetail(DataEncrypterFactory::APP_ENV_KEY, '<fg=green>untouched</>');

        if (method_exists($this->laravel, 'configurationIsCached') && $this->laravel->configurationIsCached()) {
            $this->components->warn('Config is cached — run `php artisan config:clear` or the new key will not be read.');
        }

        $this->newLine();
        $this->line('  <fg=gray>Next steps:</>');
        $this->line('  <fg=yellow>1.</> php artisan encryption:rekey');
        $this->line('  <fg=yellow>2.</> php artisan encryption:health');
        $this->line('  <fg=yellow>3.</> Clear '.DataEncrypterFactory::PREVIOUS_ENV_KEY.' in .env — ONLY after encryption:health reports OK.');
        $this->newLine();
    }

    /**
     * Whether the environment looks like production.
     *
     * Mirrors InstallCommand::isProductionLikeEnvironment() — `prod` or
     * `production` as a case-insensitive substring rather than an exact match,
     * so `prod`, `prod-eu` and `my-prod` all trip the guard. Broader is the
     * correct direction for a guard whose failure mode is unreadable data.
     */
    private function isProductionLikeEnvironment(): bool
    {
        $environment = strtolower((string) $this->laravel->environment());

        foreach (['prod', 'production'] as $keyword) {
            if (str_contains($environment, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
