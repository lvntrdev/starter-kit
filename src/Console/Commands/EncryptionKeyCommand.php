<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Console\Commands;

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;
use Dotenv\Loader\Loader;
use Dotenv\Parser\Parser;
use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\Adapter\EnvConstAdapter;
use Dotenv\Repository\Adapter\ServerConstAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Dotenv\Store\StringStore;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Filesystem\Filesystem;
use Lvntr\StarterKit\Console\Concerns\HoldsEncryptionRotationLock;
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
    use HoldsEncryptionRotationLock;

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

        return $this->withRotationLock(function () use ($envPath, $cipher): int {
            try {
                $this->rotate($envPath, $cipher);
            } catch (Throwable $e) {
                $this->components->error($e->getMessage());

                return Command::FAILURE;
            }

            return Command::SUCCESS;
        });
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
        //
        // The file is parsed ONCE, here, and the resolved values are passed down
        // by value. Two reasons, both about the write order below: a single
        // parse is the single place a malformed `.env` can abort the run, and it
        // happens before the key is even generated, so there is no state to
        // unwind; and $content is rewritten twice further down, so a parse that
        // re-read it later would be reading a body this command wrote rather
        // than the one it found. Every read below is about $initial.
        $initial = $this->files->get($envPath);

        $values = $this->parseEnv($initial, $envPath);

        [$currentPrimary, $primarySource] = $this->currentPrimary($values);

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
                implode(',', $this->prependPreviousKey($values, $currentPrimary, $primarySource)),
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
     * 3. **Ownership loss.** `rename()` also installs a NEW inode, owned by
     *    whoever ran the command. A `sudo php artisan encryption:key` over an
     *    app whose `.env` is `www-data:www-data` leaves a root-owned `0640`
     *    file the web user can no longer read — the app stops booting with the
     *    key it just rotated onto.
     *
     * So: resolve the link first and write onto its target; capture the
     * target's mode, owner and group and restore them before the rename; and
     * narrow the temp file to `0600` while it is still EMPTY, so key material
     * never exists in a wide-mode file. Atomicity is kept — the temp file stays
     * in the target's own directory, so the rename never crosses a filesystem.
     *
     * Two more things the generic writer already gets right and this one must
     * not skip: the `put()` return value is CHECKED (a full disk returns a short
     * byte count instead of throwing, and an unchecked call renames a truncated
     * body over a good `.env`), and the bytes are fsync-ed before the rename, so
     * the "previous list is on disk before the primary changes" ordering is a
     * durability guarantee and not just a call order.
     */
    private function putEnvPreservingIdentity(string $path, string $contents): void
    {
        // Follow the link BEFORE anything else: every decision below (mode,
        // temp directory, rename target) must be about the real file.
        $target = is_link($path) ? (realpath($path) ?: $path) : $path;

        $exists = file_exists($target);

        $mode = $exists ? (fileperms($target) & 0777) : 0600;
        $owner = $exists ? @fileowner($target) : false;
        $group = $exists ? @filegroup($target) : false;

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

            $this->narrowOrFail($temp);

            // put() reports a full disk or a failed open by RETURNING (false, or
            // a short byte count), not by throwing. Renaming that temp file into
            // place would replace a complete .env with a truncated one — and on
            // step (3) the truncated body is the one holding the only copy of
            // the retired key.
            $written = $this->files->put($temp, $contents);

            if ($written === false || $written !== strlen($contents)) {
                throw new RuntimeException(
                    "Refusing to replace [{$target}]: the temporary file was written short or not at all "
                    .'(a full disk reports itself this way). The existing file is untouched.'
                );
            }

            $this->restoreIdentity($temp, $target, $mode, $owner, $group);

            $this->flushToDisk($temp);

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
     * Narrow a file to owner-only and PROVE it before a key is written into it.
     *
     * chmod() returning true is not the same as the mode being applied: a FAT or
     * SMB mount, or a restrictive `open_basedir`, silently keeps the permissive
     * mode. An unverified chmod therefore reports success while the very next
     * line writes key material into a world-readable file. Fail here instead —
     * the temp file is still empty, so refusing costs nothing.
     *
     * @throws RuntimeException
     */
    private function narrowOrFail(string $temp): void
    {
        @chmod($temp, 0600);

        clearstatcache(true, $temp);

        $mode = @fileperms($temp);

        if ($mode === false || ($mode & 0077) !== 0) {
            throw new RuntimeException(
                "Refusing to write the key: [{$temp}] could not be restricted to owner-only access. "
                .'The filesystem may not support permissions (FAT, some network mounts). '
                .'Nothing was written.'
            );
        }
    }

    /**
     * Give the temp file the identity of the file it is about to replace.
     *
     * Mode alone is not the identity. `rename()` installs a NEW inode owned by
     * whoever ran the command, so a `sudo` rotation over a `www-data:www-data`
     * `.env` produces a root-owned file: the mode says `0640`, the web user
     * still cannot read it, and the app stops booting on the key that was just
     * rotated in. Ownership therefore has to be carried over explicitly — the
     * same reason {@see InstallCommand::putEnvAtomically()} carries it.
     *
     * Ownership is BEST-EFFORT: only root can hand a file to another user, and a
     * non-root run already owns the file it replaces. When it does not stick we
     * warn with the command that repairs it rather than aborting — the rotation
     * itself is sound, and refusing here would leave an operator unable to
     * rotate at all.
     *
     * The mode restore, in contrast, is VERIFIED, because chmod() reporting
     * success is not the mode being applied (see {@see self::narrowOrFail()}).
     * A mode that came back WIDER than the target is a privilege leak on a file
     * holding key material and aborts — nothing has been renamed yet, so the
     * real `.env` is untouched. A mode that stayed NARROWER (a mount that pins
     * permissions) only warns: the key is safe, the app may not be able to read
     * it, and that is the operator's call to make.
     *
     * @param  int|false  $owner  the replaced file's uid, false when unknown
     * @param  int|false  $group  the replaced file's gid, false when unknown
     *
     * @throws RuntimeException
     */
    private function restoreIdentity(string $temp, string $target, int $mode, int|false $owner, int|false $group): void
    {
        // Group before owner, both before chmod: chown() clears setuid/setgid on
        // most systems, so the mode has to be the last word.
        if ($group !== false) {
            @chgrp($temp, $group);
        }

        if ($owner !== false) {
            @chown($temp, $owner);
        }

        @chmod($temp, $mode);

        clearstatcache(true, $temp);

        $actual = @fileperms($temp);

        if ($actual === false) {
            throw new RuntimeException(
                "Refusing to replace [{$target}]: the permissions of the temporary file could not be read back, "
                .'so the key would land under unknown access. The existing file is untouched.'
            );
        }

        $actual &= 0777;

        if (($actual & ~$mode) !== 0) {
            throw new RuntimeException(sprintf(
                'Refusing to replace [%s]: the replacement file came back mode %o where the existing file is %o, '
                .'which would widen who can read the encryption key. The existing file is untouched.',
                $target,
                $actual,
                $mode,
            ));
        }

        if ($actual !== $mode) {
            $this->components->warn(sprintf(
                'The rotated .env is mode %o, not the %o the previous file carried — this filesystem pins '
                .'permissions. The key is not exposed, but a service running as another user may no longer '
                .'read .env. Fix it with: chmod %o %s',
                $actual,
                $mode,
                $mode,
                $target,
            ));
        }

        $ownerKept = $owner === false || @fileowner($temp) === $owner;
        $groupKept = $group === false || @filegroup($temp) === $group;

        if (! $ownerKept || ! $groupKept) {
            $this->components->warn(sprintf(
                'The rotated .env is owned by %s:%s, not the %s:%s the previous file carried (only root can '
                .'hand a file to another user). A service running as that user can no longer read .env. '
                .'Fix it with: chown %s:%s %s',
                (string) @fileowner($temp),
                (string) @filegroup($temp),
                $owner === false ? '?' : (string) $owner,
                $group === false ? '?' : (string) $group,
                $owner === false ? '?' : (string) $owner,
                $group === false ? '?' : (string) $group,
                $target,
            ));
        }
    }

    /**
     * Push the temp file's bytes out of the kernel page cache before the rename.
     *
     * `rename()` makes the SWAP atomic, not the CONTENT durable: on a power loss
     * or host crash the metadata change can reach the disk while the data behind
     * it has not. For this command that is the whole safety property — step (3)
     * writes the retired key into DATA_ENCRYPTION_PREVIOUS_KEYS and step (4)
     * replaces the primary, and "step 3 is on disk first" is only true if step 3
     * was actually flushed. Without this, a crash between the two can leave a
     * correctly named `.env` whose previous-key line is zeroes and whose primary
     * is a key nothing was encrypted with.
     *
     * Deliberately not {@see WritesFilesAtomically}: that trait's atomicPut() is
     * wrong for this file (see {@see self::putEnvPreservingIdentity()}), and
     * importing the trait for one helper would put the wrong writer within reach.
     *
     * The flush opens its own handle so the content write can keep going through
     * $this->files->put(), which the write-order tests observe.
     *
     * Best-effort by design: a filesystem that cannot fsync (some network and
     * container mounts) must not fail an otherwise complete write.
     */
    private function flushToDisk(string $temp): void
    {
        $handle = @fopen($temp, 'r+');

        if ($handle === false) {
            return;
        }

        try {
            @fflush($handle);
            @fsync($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * The key the app is encrypting with right now, and the env var it came
     * from. Mirrors DataEncrypterFactory's primary-key contract: the dedicated
     * key when set, otherwise APP_KEY (first adoption).
     *
     * @param  array<string, string|null>  $values  the parsed `.env` body
     * @return array{0: string|null, 1: string|null}
     */
    private function currentPrimary(array $values): array
    {
        $dedicated = $this->readEnvValue($values, DataEncrypterFactory::PRIMARY_ENV_KEY);

        if ($dedicated !== null) {
            return [$dedicated, DataEncrypterFactory::PRIMARY_ENV_KEY];
        }

        $appKey = $this->readEnvValue($values, DataEncrypterFactory::APP_ENV_KEY);

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
     * @param  array<string, string|null>  $values  the parsed `.env` body
     * @return list<string>
     */
    private function prependPreviousKey(array $values, #[SensitiveParameter] string $currentPrimary, string $source): array
    {
        $list = [$this->envSafeValue($currentPrimary, $source)];

        foreach ($this->existingPreviousKeys($values) as $entry) {
            // Existing entries are made env-safe too, never copied through
            // verbatim. They were READ through phpdotenv — surrounding quotes
            // stripped, `${VAR}` resolved — and they are WRITTEN back UNQUOTED,
            // so an entry carrying `#`, `$`, a quote or whitespace comes back
            // meaning something else on the next boot: `#` opens a comment and
            // truncates the key, `$` interpolates. A retired key that changes
            // meaning between two boots is unrecoverable data — the one failure
            // this command exists to prevent. The re-emitted `base64:` form
            // decodes to the identical bytes. The source name is
            // PREVIOUS_ENV_KEY so a malformed entry aborts naming the right
            // variable, and aborting is not a new break: DataEncrypterFactory
            // parses every entry of the chain at boot, so that entry was
            // already failing the application.
            $safe = $this->envSafeValue($entry, DataEncrypterFactory::PREVIOUS_ENV_KEY);

            if (! in_array($safe, $list, true)) {
                $list[] = $safe;
            }
        }

        return $list;
    }

    /**
     * Existing DATA_ENCRYPTION_PREVIOUS_KEYS entries, trimmed, blanks dropped.
     *
     * @param  array<string, string|null>  $values  the parsed `.env` body
     * @return list<string>
     */
    private function existingPreviousKeys(array $values): array
    {
        $raw = $this->readEnvValue($values, DataEncrypterFactory::PREVIOUS_ENV_KEY);

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
     * Parse an `.env` body with phpdotenv, or abort the rotation.
     *
     * This runs before a key is generated and long before anything is written,
     * so a body this parser rejects costs nothing but the run. That is the point:
     * the alternative is guessing at a file we cannot read and then writing key
     * material according to the guess.
     *
     * The parser's own message is deliberately DISCARDED rather than forwarded.
     * `InvalidFileException` quotes the offending fragment back —
     * `Encountered a missing closing quote at ['…]` — and when the malformed
     * line is the key line, that fragment is key material on the operator's
     * terminal and in their scrollback. The replacement names the file and the
     * variables that could not be read, and nothing else.
     *
     * A THROWN parse is not the only way this file can be unreadable, and it is
     * not the dangerous one. phpdotenv's commonest real malformation — a quote
     * opened and never closed — throws nothing: the value simply runs to EOF and
     * every assignment after it silently disappears from the result. That is the
     * shape that destroys key material, because the read side would then report
     * "no previous keys" while setEnvValue()'s line-level regex still sees, and
     * overwrites, the DATA_ENCRYPTION_PREVIOUS_KEYS line that is right there in
     * the file. So the parse is cross-checked against the raw body: a variable
     * the file assigns but the parser did not return means the parse is partial,
     * and a partial parse aborts exactly like a rejected one.
     *
     * @return array<string, string|null>
     *
     * @throws RuntimeException naming the file and the env vars, never a value
     */
    private function parseEnv(string $content, string $path): array
    {
        try {
            $values = Dotenv::parse($content);

            foreach ([
                DataEncrypterFactory::APP_ENV_KEY,
                DataEncrypterFactory::PRIMARY_ENV_KEY,
                DataEncrypterFactory::PREVIOUS_ENV_KEY,
            ] as $key) {
                if (preg_match($this->assignmentPattern($key), $content) === 1
                    && ! array_key_exists($key, $values)) {
                    throw new InvalidFileException;
                }
            }

            $this->assertFileDecidesTheKeys($content, $values, $path);

            return $values;
        } catch (InvalidFileException) {
            throw new RuntimeException(sprintf(
                'Could not read [%s] in full, so %s and %s cannot be trusted. Nothing was written. '
                .'The parser error is withheld here because it would quote the malformed line, '
                .'which may be key material. Fix the file by hand — and do NOT clear %s while '
                .'fixing it, because a key dropped from that list is unrecoverable.',
                $path,
                DataEncrypterFactory::PRIMARY_ENV_KEY,
                DataEncrypterFactory::PREVIOUS_ENV_KEY,
                DataEncrypterFactory::PREVIOUS_ENV_KEY,
            ));
        }
    }

    /**
     * Stop unless the FILE is what decides these three variables.
     *
     * `Dotenv::parse()` resolves against an isolated array repository: the file
     * and nothing but the file. The booted application resolves against an
     * IMMUTABLE repository whose readers are `$_SERVER`, `$_ENV` and `getenv()`,
     * so anything the process injects WINS over the same name in `.env` — and
     * wins for interpolation too, which is the quiet version: with
     * `DATA_ENCRYPTION_KEY=${BASE_KEY}` in the file, an injected `BASE_KEY`
     * makes the app encrypt with one key while this command reads another.
     *
     * Rotating on the file's answer there would prepend a key the data was
     * never encrypted with, and the real one would never enter the list —
     * unrecoverable. Rewriting `.env` would not even change what the app uses,
     * because the injection still wins on the next boot. So this is a stop, not
     * a preference: the operator has to resolve the override themselves.
     *
     * The comparison runs through phpdotenv's own resolver: `$_SERVER` and
     * `$_ENV` are added as READERS, and an `ArrayAdapter` — an in-memory store
     * that dies with this call — is the only thing written to. Reader order is
     * what encodes "the process wins", exactly as the immutable repository the
     * framework boots with does.
     *
     * @param  array<string, string|null>  $fileValues
     *
     * @throws RuntimeException naming the file and the env var, never a value
     */
    private function assertFileDecidesTheKeys(string $content, array $fileValues, string $path): void
    {
        $repository = RepositoryBuilder::createWithNoAdapters()
            ->addReader(ServerConstAdapter::class)
            ->addReader(EnvConstAdapter::class)
            ->addAdapter(ArrayAdapter::class)
            ->immutable()
            ->make();

        (new Dotenv(new StringStore($content), new Parser, new Loader, $repository))->load();

        foreach ([
            DataEncrypterFactory::APP_ENV_KEY,
            DataEncrypterFactory::PRIMARY_ENV_KEY,
            DataEncrypterFactory::PREVIOUS_ENV_KEY,
        ] as $key) {
            $effective = $repository->get($key);
            $fromFile = $fileValues[$key] ?? null;

            if ($effective === $fromFile) {
                continue;
            }

            throw new RuntimeException(sprintf(
                'The process environment overrides [%s] from [%s] — directly or through a variable '
                .'that its value interpolates — so the application is using a different value than '
                .'this file states. Nothing was written: rotating the file would record the wrong '
                .'key as previous AND would not change what the application loads. Unset the '
                .'process override (or rotate through it instead) and run this again.',
                $key,
                $path,
            ));
        }
    }

    /**
     * One key's effective value out of an already-parsed `.env` body.
     *
     * phpdotenv resolves this, not a regex, because this is the WRITE path: the
     * value it returns is the one prepended to DATA_ENCRYPTION_PREVIOUS_KEYS,
     * and a read that disagrees with the running app preserves a key the data
     * was never encrypted with while the real one is overwritten. Only the
     * parser the app boots with can agree with the app by construction. The
     * regex this replaced did not: it kept the inline comment in
     * `KEY=base64:… # rotated`, and handed back `${APP_KEY}` verbatim for an
     * interpolated assignment the app resolves — see
     * `Support\Encryption\EncrypterCoverage::environmentFileValue()`, which made
     * the same move for the read-only health path and records the reasoning in
     * full.
     *
     * Every promise the regex made is kept, and each is pinned by a test rather
     * than assumed: a commented-out line is ignored, `export ` is tolerated,
     * surrounding quotes are stripped, and the LAST of several assignments wins
     * (phpdotenv's immutable repository only protects variables defined OUTSIDE
     * the file, so a later line in the same file does overwrite an earlier one).
     * Inline comments, escapes and `${VAR}` interpolation are now handled too.
     *
     * A key that is PRESENT but blank stays null, so the shipped
     * `DATA_ENCRYPTION_KEY=` placeholder keeps meaning "not set" and first
     * adoption still falls through to APP_KEY.
     *
     * @param  array<string, string|null>  $values
     */
    private function readEnvValue(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        return $value === null || trim($value) === '' ? null : $value;
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
     *
     * When config is cached, `config:clear` is listed as its own numbered step
     * BEFORE the rekey rather than only as a warning above the list. The key
     * this command just wrote lives in `.env`; a cached config keeps serving the
     * previous chain, so a rekey run first re-encrypts every row onto the key
     * that was just retired, or finds nothing to do. The documented runbook puts
     * the clear between the two commands and this output has to say the same
     * thing — an operator following the numbered list must not end up somewhere
     * else than one following docs/encryption.md.
     */
    private function report(?string $primarySource): void
    {
        $this->newLine();
        $this->components->info('A new '.DataEncrypterFactory::PRIMARY_ENV_KEY.' was written to .env.');

        if ($primarySource !== null) {
            $this->components->twoColumnDetail('Previous key preserved from', '<fg=green>'.$primarySource.'</>');
        }

        $this->components->twoColumnDetail(DataEncrypterFactory::APP_ENV_KEY, '<fg=green>untouched</>');

        $configIsCached = method_exists($this->laravel, 'configurationIsCached') && $this->laravel->configurationIsCached();

        if ($configIsCached) {
            $this->components->warn('Config is cached — run `php artisan config:clear` or the new key will not be read.');
        }

        $steps = [];

        if ($configIsCached) {
            $steps[] = 'php artisan config:clear   <fg=gray>(before the rekey — otherwise it still resolves the OLD key)</>';
        }

        $steps[] = 'php artisan encryption:rekey';
        $steps[] = 'php artisan encryption:health';
        $steps[] = 'Clear '.DataEncrypterFactory::PREVIOUS_ENV_KEY.' in .env — ONLY after encryption:health reports OK.';

        $this->newLine();
        $this->line('  <fg=gray>Next steps:</>');

        foreach ($steps as $index => $step) {
            $this->line('  <fg=yellow>'.($index + 1).'.</> '.$step);
        }

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
