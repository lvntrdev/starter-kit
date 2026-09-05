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
use Illuminate\Support\Facades\Log;
use Lvntr\StarterKit\Console\Commands\Concerns\RefusesPackageSourceTree;
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
 * The only lines this command logs are the two `--allow-acl-loss` downgrades —
 * {@see self::carryOverAcl()} for an ACL that could not be carried over, and
 * {@see self::normaliseTempAcl()} for one the replacement inherited and could
 * not shed — and both carry a file path and ACL text, never a value out of
 * `.env`. Values that reach an exception message do so through
 * {@see DataEncrypterFactory::parseKey()}, which withholds key material by
 * design.
 */
final class EncryptionKeyCommand extends Command
{
    use HoldsEncryptionRotationLock;
    use RefusesPackageSourceTree;

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

    /**
     * Option name for the deliberate-ACL-mismatch escape hatch, referenced from
     * both refusal messages so the flag and the text can never drift apart.
     *
     * The name says "loss" because that was the first direction it covered — an
     * ACL on `.env` that the replacement could not be given. It now covers the
     * MIRROR direction too ({@see self::normaliseTempAcl()}): an ACL the
     * replacement carries and `.env` does not. Renaming the option would break
     * every deploy script that already passes it, so the help text carries the
     * widened meaning instead.
     */
    private const ACL_LOSS_OPTION = 'allow-acl-loss';

    protected $signature = 'encryption:key
        {--show : Print a freshly generated key and write nothing}
        {--force : Run even when the environment looks like production}
        {--allow-acl-loss : Rotate even when the ACL of the replacement file cannot be made to match the ACL of .env: a file-specific ACL that could not be carried over, or one inherited from the directory that could not be cleared}';

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
        if (! $this->option('show') && $this->isPackageSourceTree()) {
            return $this->renderPackageSourceTreeStop();
        }

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
     * durability guarantee and not just a call order. Every one of those checks
     * fails CLOSED: a short write, an unrestorable owner/group, a mode that came
     * back different, a flush that did not happen — each aborts before the
     * rename, so the outcome is a rotation that did not occur rather than an
     * `.env` the service cannot read or a key that never reached the disk.
     *
     * The ORDER of the last two steps is load-bearing and reads backwards, so:
     * the flush runs BEFORE {@see self::restoreIdentity()}, not after. A
     * hardened `.env` is commonly `0400` or `0440`, and POSIX applies the owner
     * class verbatim — a mode with no owner-write bit makes `fopen($temp, 'r+')`
     * fail for every non-root owner. Restoring the identity first therefore made
     * {@see self::flushToDisk()} abort with "could not be reopened to flush" on
     * exactly the permissions this kit tells operators to set: rotation was
     * impossible on a hardened `.env`, which pushes the operator toward widening
     * the file that holds the encryption key. Flushing while the temp file still
     * carries the `0600` that {@see self::narrowOrFail()} PROVED keeps the handle
     * openable, and it costs the durability argument nothing: restoreIdentity()
     * only chgrp/chown/chmods — it never writes to the file BODY, so there is no
     * byte left unflushed behind it, and its own metadata change is ordered
     * ahead of the rename by the same journal that orders the rename itself.
     * Do not swap these back.
     *
     * ## The ACL is part of the identity too
     *
     * Mode, owner and group are only the POSIX third of "who can read this
     * file". An operator who granted the web user read access to a `0600` `.env`
     * with `setfacl -m u:www-data:r` (or macOS `chmod +a`) holds that grant in a
     * file-specific ACL, and `rename()` drops it exactly the way it drops the
     * mode — except nothing above would notice, because `fileperms()` reports
     * `0600` before AND after. The service then cannot read `.env` at the moment
     * its key changed. So the ACL is captured with the rest of the identity and
     * re-applied by {@see self::carryOverAcl()}, which fails CLOSED like every
     * other check here. That step runs AFTER {@see self::restoreIdentity()} on
     * purpose: on Linux `setfacl` writes the ACL's mask into the mode's group
     * bits, so a chmod afterwards would rewrite the mask that was just verified.
     * A platform or host without ACL tooling reads back `null` and changes
     * nothing at all — see {@see self::readFileAcl()}.
     *
     * ## …and the ACL runs in BOTH directions
     *
     * carryOverAcl() answers "does the replacement keep what `.env` granted".
     * The mirror question — "does the replacement grant something `.env` never
     * did" — has its own answer, {@see self::normaliseTempAcl()}, and it is
     * asked EARLIER on purpose. The temp file is created inside `.env`'s own
     * directory, so a directory-level inheritance rule (`setfacl -d -m …`,
     * `chmod +a "… file_inherit"`) puts an entry on it that the target never
     * had, and narrowOrFail() cannot see it: `fileperms() & 0077` reports `0600`
     * with the inherited grant sitting right next to it. Normalising AFTER the
     * body write would already be too late — the key would have spent that
     * window readable by the inherited principal — so the order here is
     * touch → narrowOrFail() → normaliseTempAcl() → write the key. There is no
     * point in the file's life at which it holds key material and an ACL entry
     * the file it replaces does not have.
     */
    private function putEnvPreservingIdentity(string $path, string $contents): void
    {
        // Follow the link BEFORE anything else: every decision below (mode,
        // temp directory, rename target) must be about the real file.
        $target = is_link($path) ? (realpath($path) ?: $path) : $path;

        [$mode, $owner, $group] = $this->fileIdentity($target);

        // Captured together with the mode/owner/group, before anything exists
        // next to the target: this is the identity the replacement must carry.
        $acl = $this->readFileAcl($target);

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

            // BEFORE the key exists in this file: the mode is only the POSIX
            // third of "who can read it", and a directory that carries an
            // inheritance rule has already put an ACL entry on the file touch()
            // just made. Shedding it after the write would leave a window in
            // which the rotated key was readable by a principal .env excludes.
            $this->normaliseTempAcl($temp, $target, $acl, (bool) $this->option(self::ACL_LOSS_OPTION));

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

            // FLUSH FIRST, identity SECOND — see the docblock. flushToDisk()
            // reopens this file `r+`, which POSIX refuses to a non-root owner
            // once the mode has lost its owner-write bit, so restoring a
            // 0400/0440 identity first made the rotation abort on exactly the
            // hardened permissions the kit recommends. Flushing while the file
            // still carries the 0600 narrowOrFail() PROVED keeps the handle
            // openable; restoreIdentity() writes nothing to the body, so it
            // cannot leave anything unflushed behind it.
            $this->flushToDisk($temp, $target);

            $this->restoreIdentity($temp, $target, $mode, $owner, $group);

            // ACL LAST of the identity steps: on Linux setfacl folds the ACL
            // mask into the mode's group bits, so a chmod after it would
            // rewrite what was just verified. Reading the option here (not
            // inside the helper) keeps every writer helper callable without
            // command IO, which the identity tests depend on.
            $this->carryOverAcl($temp, $target, $acl, (bool) $this->option(self::ACL_LOSS_OPTION));

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
     * Restoring is ATTEMPTED best-effort — only root can hand a file to another
     * user — but the RESULT is verified and a mismatch ABORTS. Warning and
     * renaming anyway was the wrong trade: the operator who cannot chown is
     * exactly the deploy user who CAN rename into the directory, so the warning
     * shipped a `.env` the service could no longer read while reporting success.
     * Refusing costs a rotation that has not happened yet; continuing costs the
     * running app. Nothing is renamed on this path, so the real `.env` — and the
     * key it holds — is untouched either way.
     *
     * The mode is verified for the same reason and by the same rule: chmod()
     * reporting success is not the mode being applied (see
     * {@see self::narrowOrFail()}). A mode that came back WIDER leaks the key to
     * readers the operator excluded; one that came back NARROWER locks out the
     * service that has to read it. Both abort.
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
            throw new RuntimeException(sprintf(
                'Refusing to replace [%s]: the replacement file came back mode %o where the existing file is '
                .'%o, so a service that reads .env through the missing bits would lose access. This filesystem '
                .'pins permissions. The existing file is untouched.',
                $target,
                $actual,
                $mode,
            ));
        }

        clearstatcache(true, $temp);

        $actualOwner = @fileowner($temp);
        $actualGroup = @filegroup($temp);

        if (($owner !== false && $actualOwner !== $owner) || ($group !== false && $actualGroup !== $group)) {
            throw new RuntimeException(sprintf(
                'Refusing to replace [%s]: the replacement file is owned by %s:%s and the existing file by '
                .'%s:%s, and the ownership could not be handed back (only root can). A service running as that '
                .'user would no longer be able to read .env. The existing file is untouched — re-run as a user '
                .'that can chown, or run: chown %s:%s %s after rotating.',
                $target,
                $actualOwner === false ? '?' : (string) $actualOwner,
                $actualGroup === false ? '?' : (string) $actualGroup,
                $owner === false ? '?' : (string) $owner,
                $group === false ? '?' : (string) $group,
                $owner === false ? '?' : (string) $owner,
                $group === false ? '?' : (string) $group,
                $target,
            ));
        }
    }

    /**
     * The mode, owner and group of the file about to be replaced.
     *
     * Read in one place so the writer captures the identity of the file it will
     * replace BEFORE it creates anything, and restores that same triple after
     * the body is written.
     *
     * @return array{0: int, 1: int|false, 2: int|false}
     */
    private function fileIdentity(string $path): array
    {
        if (! file_exists($path)) {
            return [0600, false, false];
        }

        return [fileperms($path) & 0777, @fileowner($path), @filegroup($path)];
    }

    /**
     * Strip from the replacement any ACL grant the replaced file does not have,
     * BEFORE a single byte of key material is written into it.
     *
     * The temp file is created next to `.env`, in `.env`'s own directory, and a
     * directory can carry an inheritance rule — `setfacl -d -m u:deploy:r` on
     * Linux, `chmod +a "deploy allow read,file_inherit"` on macOS. Every file
     * created in it is then born with that entry, including this one. Nothing
     * else in this class notices: {@see self::narrowOrFail()} proves
     * `fileperms() & 0077 === 0` and that stays true — a POSIX mode simply does
     * not describe an ACL — and {@see self::carryOverAcl()} looks only at
     * whether the TARGET's ACL survived, so a `''` target (no ACL at all, the
     * common case) makes it return without ever inspecting the temp. The rename
     * then installs the inherited grant as the new `.env`, and the account named
     * in it can read the key that was just rotated in. The mode reads `0600`
     * before and after, so there is nothing on screen to notice either.
     *
     * ## Ordering
     *
     * This runs between narrowOrFail() and the body write, so the file is empty
     * while its ACL is being corrected and the key lands only into a file whose
     * access set is already a subset of the target's. Fixing it later — next to
     * carryOverAcl(), after the write — would close the hole for the FINAL file
     * but leave the key sitting in a temp file the inherited principal could
     * read for the whole write/fsync window. Do not move it.
     *
     * ## What is acted on, and what is deliberately not
     *
     * `$acl` is the TARGET's ACL as {@see self::readFileAcl()} reports it,
     * `$current` the temp's. Four of the five combinations do nothing:
     *
     * - `$acl === null` — no ACL tooling on this host/platform. Strict no-op,
     *   exactly as before this check existed: a stock container without
     *   `getfacl` must not start refusing rotations.
     * - `$current === null` — unreachable in practice (a non-null `$acl` proves
     *   the reader works in this very directory, and the temp was just created
     *   and stat'd), and treated as the same unknown for the same reason.
     * - `$current === ''` — the temp carries no file-specific ACL, so it cannot
     *   grant anything. Nothing to strip. This is the branch EVERY rotation on
     *   a directory without an inheritance rule takes, which is why this check
     *   adds no new refusal to hosts that do not have the problem — and why an
     *   ACL that merely failed to be carried OVER is still carryOverAcl's single
     *   report, not two.
     * - `$current === $acl` — already an exact mirror of the target.
     *
     * Only a temp that carries a non-empty ACL DIFFERENT from the target's is
     * touched: the target's ACL is written onto it when the target has one, and
     * the temp's ACL is cleared when the target provably has none (`''`). The
     * clear is gated on `''` and never on `null` on purpose — clearing is the
     * wider operation, and treating "the tooling could not answer" as "there is
     * nothing to keep" would strip ACLs on every host where the reader failed.
     *
     * The result is VERIFIED by re-reading, for the same reason every other
     * guard here verifies: the tool reporting success is not the file having
     * changed. A mismatch refuses, and refusing costs nothing — the temp is
     * empty and `.env` has not been touched. `--allow-acl-loss` downgrades it to
     * a warning on screen AND in the log, the same escape hatch, in the same
     * voice, as the opposite direction.
     *
     * @param  string|null  $acl  the target's ACL, `''` for none, null for unknown
     *
     * @throws RuntimeException
     */
    private function normaliseTempAcl(string $temp, string $target, ?string $acl, bool $allowMismatch): void
    {
        if ($acl === null) {
            return;
        }

        $current = $this->readFileAcl($temp);

        if ($current === null || $current === '' || $current === $acl) {
            return;
        }

        $failure = $acl === ''
            ? $this->clearFileAcl($temp)
            : $this->writeFileAcl($temp, $acl);

        $result = $failure === null ? $this->readFileAcl($temp) : null;

        if ($result !== $acl) {
            $this->reportTempAclMismatch(
                $target,
                $current,
                $failure ?? 'the ACL read back from the replacement file still did not match the original',
                $allowMismatch,
            );
        }

        // Both ACL tools rewrite the mode while they work — Linux setfacl folds
        // the ACL mask into the group bits, and `setfacl -b` hands them back to
        // the group entry — so the owner-write bit the body write needs, and the
        // owner-only guarantee the key write depends on, are re-proved here
        // rather than assumed. This also runs after a downgraded mismatch: the
        // operator accepted an ACL they can see, not a widened mode nobody
        // reported.
        $this->narrowOrFail($temp);
    }

    /**
     * The refusal (or, under `--allow-acl-loss`, the warning) for a replacement
     * file that carries an ACL entry the file it replaces does not have.
     *
     * Kept beside {@see self::normaliseTempAcl()} rather than inlined so the
     * decision above reads as three lines of policy. Only the path and the ACL
     * text are emitted: neither is key material, and the ACL is precisely what
     * the operator needs in order to act on the report.
     *
     * @throws RuntimeException
     */
    private function reportTempAclMismatch(string $target, string $current, string $reason, bool $allowMismatch): void
    {
        $acl = str_replace("\n", ' | ', $current);

        if ($allowMismatch) {
            Log::warning(sprintf(
                'encryption:key replaced [%s] with a file WHOSE INHERITED ACL COULD NOT BE NORMALISED (%s). --%s '
                .'was given, so the rotation continued and the rotated key is now reachable through that entry. '
                .'Review it: %s',
                $target,
                $reason,
                self::ACL_LOSS_OPTION,
                $acl,
            ));

            $this->components->warn(sprintf(
                'The replacement for [%s] carries a file-specific ACL that [%s] does not (%s) — a directory-level '
                .'inheritance rule put it there. --%s was given, so the rotation continued, and the account named '
                .'in that entry can read the rotated key. Remove it by hand. The ACL was: %s',
                $target,
                $target,
                $reason,
                self::ACL_LOSS_OPTION,
                $acl,
            ));

            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to replace [%s]: the temporary file created next to it inherited a file-specific ACL that '
            .'[%s] does not carry, and it could not be normalised (%s). A directory-level inheritance rule '
            .'(setfacl -d on Linux, chmod +a with file_inherit on macOS) puts that entry on every file created in '
            .'the directory, so renaming anyway would widen who can read the encryption key while the mode still '
            .'reads %s. The existing file is untouched. Drop the inherited entry from the directory, or re-run '
            .'with --%s to accept the mismatch. The inherited ACL is: %s',
            $target,
            $target,
            $reason,
            sprintf('%o', fileperms($target) & 0777),
            self::ACL_LOSS_OPTION,
            $acl,
        ));
    }

    /**
     * Carry the replaced file's ACL onto the replacement, or refuse the rename.
     *
     * `$acl` is what {@see self::readFileAcl()} found on the target. Two of its
     * three possible values mean "do nothing", and that is deliberate:
     *
     * - `null` — this platform or this host cannot report an ACL (no `getfacl`,
     *   a Windows/BSD runner, `exec()` in `disable_functions`). Unknown is NOT
     *   treated as "there is one": an install without ACL tooling has to behave
     *   exactly as it did before this check existed, or a stock Linux container
     *   would start refusing every rotation over a file that has no ACL at all.
     * - `''` — tooling answered and the file carries no file-specific ACL. The
     *   overwhelming majority of installs; nothing to preserve, nothing to do.
     *
     * A non-empty ACL is re-applied and then VERIFIED by re-reading, because the
     * tool reporting success is not the ACL being on the file — the same reason
     * {@see self::narrowOrFail()} and {@see self::restoreIdentity()} verify their
     * own writes. An ACL that silently did not take is the failure this exists to
     * catch: it is invisible in `ls -l`, so the operator sees a `0600` `.env`
     * before and after and no sign that the `u:www-data:r` grant their service
     * boots through is gone.
     *
     * `--allow-acl-loss` downgrades the refusal to a warning on screen AND in the
     * log. Deliberate loss stays reachable — an operator who is about to re-apply
     * the ACL by hand should not be blocked — but it is never silent, and the log
     * line is what makes it auditable after the terminal is closed.
     *
     * @param  string|null  $acl  the target's ACL, `''` for none, null for unknown
     *
     * @throws RuntimeException
     */
    private function carryOverAcl(string $temp, string $target, ?string $acl, bool $allowLoss): void
    {
        if ($acl === null || $acl === '') {
            return;
        }

        $failure = $this->writeFileAcl($temp, $acl);

        if ($failure === null && $this->readFileAcl($temp) === $acl) {
            return;
        }

        $reason = $failure ?? 'the ACL read back from the replacement file did not match the original';

        if ($allowLoss) {
            // Path and ACL text only. Neither is key material, and the ACL is
            // the one thing the operator needs in order to put it back.
            Log::warning(sprintf(
                'encryption:key replaced [%s] WITHOUT its file-specific ACL (%s). --%s was given, so the '
                .'rotation continued. Re-apply the ACL by hand: %s',
                $target,
                $reason,
                self::ACL_LOSS_OPTION,
                str_replace("\n", ' | ', $acl),
            ));

            $this->components->warn(sprintf(
                'The file-specific ACL on [%s] was NOT carried over (%s). --%s was given, so the rotation '
                .'continued. Re-apply it by hand — until then, only the POSIX mode governs who can read the '
                .'encryption key. The ACL was: %s',
                $target,
                $reason,
                self::ACL_LOSS_OPTION,
                str_replace("\n", ' | ', $acl),
            ));

            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to replace [%s]: it carries a file-specific ACL that could not be carried over to the '
            .'replacement file (%s). Renaming anyway would drop the ACL silently — the mode would still read '
            .'%s, while a service that reaches .env through the ACL would lose the key it was just given. The '
            .'existing file is untouched. Re-apply the ACL by hand after rotating, or re-run with --%s to '
            .'accept the loss. The ACL is: %s',
            $target,
            $reason,
            sprintf('%o', fileperms($target) & 0777),
            self::ACL_LOSS_OPTION,
            str_replace("\n", ' | ', $acl),
        ));
    }

    /**
     * A file's file-specific ACL as a normalised string, or null when this
     * platform/host cannot say.
     *
     * The `null` return is the whole safety design of this feature: it is the
     * DEFAULT for everything that is not a Linux or macOS host with working ACL
     * tooling and a callable `exec()`, and {@see self::carryOverAcl()} treats it
     * as "change nothing". Turning an unknown into a refusal would break
     * rotation on a stock container that has no `getfacl` and no ACL either.
     *
     * Platform commands, both reading only the file named on the command line:
     *
     * - macOS has no `getfacl`; `ls -lde` prints the long listing and then the
     *   ACL as NUMBERED entries. Only the numbered lines are kept — the listing
     *   line carries the PATH, and target and temp have different names, so
     *   including it would make every comparison fail.
     * - Linux `getfacl --omit-header --skip-base` prints nothing at all for a
     *   file whose ACL is just its mode, which is exactly the "no file-specific
     *   ACL" answer, and prints the COMPLETE ACL (base entries included) for one
     *   that has more — the complete form `setfacl --set-file` requires.
     *
     * The path is passed through escapeshellarg(): base_path() is not
     * attacker-controlled, but a space or a quote in a deploy directory is
     * ordinary, and an unescaped path would turn a working rotation into a
     * shell-parsed one.
     */
    private function readFileAcl(string $path): ?string
    {
        if (! function_exists('exec') || ! is_file($path)) {
            return null;
        }

        $quoted = escapeshellarg($path);

        $command = match ($this->osFamily()) {
            'Darwin' => 'ls -lde '.$quoted.' 2>/dev/null',
            'Linux' => 'getfacl --omit-header --skip-base -- '.$quoted.' 2>/dev/null',
            default => null,
        };

        if ($command === null) {
            return null;
        }

        $lines = [];
        $status = 1;

        @exec($command, $lines, $status);

        // A missing tool exits 127, an unreadable file non-zero: both are
        // "cannot say", never "there is no ACL".
        return $status === 0 ? $this->normaliseAcl($lines) : null;
    }

    /**
     * Replace $path's ACL with $acl. Returns the tool's own message on failure,
     * null on success.
     *
     * macOS ships no `setfacl`; `chmod -E` reads a complete ACL from STDIN in
     * the very format `ls -le` prints, which is why the reader keeps that format
     * verbatim. Linux `setfacl --set-file=-` is the same contract.
     *
     * Both the path and the ACL text go through escapeshellarg() — the ACL text
     * is multi-line and carries account names, and `printf %s` re-emits it
     * byte-for-byte with no format expansion (the format string is the literal
     * `%s`; the ACL is the ARGUMENT, so a `%` inside it is data). The trailing
     * newline is added before escaping rather than as a shell `\n` escape, so
     * nothing here depends on how the shell renders backslashes.
     */
    private function writeFileAcl(string $path, string $acl): ?string
    {
        if (! function_exists('exec')) {
            return 'exec() is unavailable, so no ACL tool could be run';
        }

        $target = escapeshellarg($path);
        $payload = escapeshellarg($acl."\n");

        $command = match ($this->osFamily()) {
            'Darwin' => 'printf %s '.$payload.' | chmod -E '.$target.' 2>&1',
            'Linux' => 'printf %s '.$payload.' | setfacl --set-file=- -- '.$target.' 2>&1',
            default => null,
        };

        if ($command === null) {
            return 'this platform has no supported ACL tool';
        }

        $output = [];
        $status = 1;

        @exec($command, $output, $status);

        if ($status === 0) {
            return null;
        }

        $message = trim(implode(' ', $output));

        return $message === '' ? 'the ACL tool exited with status '.$status : $message;
    }

    /**
     * Remove $path's file-specific ACL entirely, leaving the POSIX mode as the
     * only thing that governs access. Returns the tool's own message on failure,
     * null on success.
     *
     * The counterpart of {@see self::writeFileAcl()}, and a separate command
     * rather than "write the empty ACL": neither tool accepts an empty document
     * as "no ACL" — Linux `setfacl --set-file=-` rejects it, and macOS
     * `chmod -E` reads it as a parse error — so the reset has to be asked for by
     * name. `chmod -N` on macOS and `setfacl -b` on Linux both keep the base
     * owner/group/other entries and drop only the extended ones, which is
     * exactly the "no file-specific ACL" state {@see self::readFileAcl()}
     * reports as `''`.
     *
     * Reaching this is narrow by construction — see {@see self::normaliseTempAcl()},
     * the sole caller: it runs only against a temp file that provably carries an
     * ACL, only when the file being replaced provably carries none, and never on
     * the `null` (cannot say) answer. Clearing is the wider of the two
     * operations and it never runs on an unknown.
     *
     * Note that on Linux dropping the ACL also drops its mask, which hands the
     * mode's group bits back to the `group::` entry and can therefore WIDEN the
     * mode. The caller re-runs {@see self::narrowOrFail()} afterwards for that
     * reason; do not treat a successful clear as leaving the mode alone.
     */
    private function clearFileAcl(string $path): ?string
    {
        if (! function_exists('exec')) {
            return 'exec() is unavailable, so no ACL tool could be run';
        }

        $quoted = escapeshellarg($path);

        $command = match ($this->osFamily()) {
            'Darwin' => 'chmod -N '.$quoted.' 2>&1',
            'Linux' => 'setfacl -b -- '.$quoted.' 2>&1',
            default => null,
        };

        if ($command === null) {
            return 'this platform has no supported ACL tool';
        }

        $output = [];
        $status = 1;

        @exec($command, $output, $status);

        if ($status === 0) {
            return null;
        }

        $message = trim(implode(' ', $output));

        return $message === '' ? 'the ACL tool exited with status '.$status : $message;
    }

    /**
     * ACL tool output reduced to the entries themselves, so the target's ACL and
     * the replacement's compare as equal when they mean the same thing.
     *
     * Three kinds of noise are removed. On macOS every non-numbered line — the
     * `ls` listing line, which carries the file's own PATH and would make the
     * comparison structurally impossible. Everywhere: comments, because
     * `getfacl` annotates masked entries with a trailing `#effective:` note that
     * is DERIVED from the `mask::` entry, which is compared on its own line
     * anyway. And blank lines.
     *
     * Entry ORDER is preserved, never sorted: macOS evaluates an ACL top-down,
     * so a `deny` ahead of an `allow` is not the same ACL as the reverse.
     *
     * @param  list<string>  $lines
     */
    private function normaliseAcl(array $lines): string
    {
        $entries = [];

        foreach ($lines as $line) {
            if ($this->osFamily() === 'Darwin') {
                if (preg_match('/^\s*\d+:\s*(.*)$/', $line, $matches) !== 1) {
                    continue;
                }

                $line = $matches[1];
            }

            $line = trim((string) preg_replace('/#.*$/', '', $line));

            if ($line !== '') {
                $entries[] = $line;
            }
        }

        return implode("\n", $entries);
    }

    /**
     * The OS family, behind a method on purpose: read as the bare constant, the
     * platform switches above fold into whichever host ran static analysis, and
     * the other platform's arm reads as dead code.
     */
    private function osFamily(): string
    {
        return PHP_OS_FAMILY;
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
     * NOT best-effort here, unlike the generic writer: a flush that silently did
     * nothing turns the ordering above back into a call order, and the failure
     * it stops being able to prevent is the unrecoverable one. So an unopenable
     * handle, a failed fflush() and a failed fsync() all abort BEFORE the
     * rename — the existing `.env` is untouched and the operator is told the
     * rotation did not happen, which is the outcome a filesystem that cannot
     * fsync should produce for a file whose whole safety property is durability.
     *
     * The rename itself is deliberately NOT followed by a directory fsync. A
     * rename that does not survive a crash leaves the PREVIOUS `.env` in place —
     * the old primary, the old list, fully readable — which is a safe outcome,
     * not a lossy one.
     *
     * @throws RuntimeException
     */
    private function flushToDisk(string $temp, string $target): void
    {
        $handle = @fopen($temp, 'r+');

        if ($handle === false) {
            throw new RuntimeException(
                "Refusing to replace [{$target}]: the replacement file could not be reopened to flush it to "
                .'disk, so the write could not be made durable. The existing file is untouched.'
            );
        }

        try {
            $flushed = @fflush($handle) && @fsync($handle);
        } finally {
            fclose($handle);
        }

        if (! $flushed) {
            throw new RuntimeException(sprintf(
                'Refusing to replace [%s]: the replacement file could not be flushed to disk (this filesystem '
                .'may not support fsync). Renaming it anyway would drop the guarantee that the retired key '
                .'reaches the disk BEFORE the primary key changes, which is the one failure this command '
                .'exists to prevent. The existing file is untouched.',
                $target,
            ));
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
