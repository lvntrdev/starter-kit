<?php

/*
|--------------------------------------------------------------------------
| encryption:key — the only code path in the kit that writes key material
|--------------------------------------------------------------------------
|
| This file locks the properties whose failure mode is UNRECOVERABLE data, not
| a red test in CI:
|
|   1. WRITE ORDER. The old primary lands in DATA_ENCRYPTION_PREVIOUS_KEYS in a
|      write that is FLUSHED TO DISK BEFORE the new DATA_ENCRYPTION_KEY is
|      written. The end state of the two possible orders is IDENTICAL, so an
|      end-state assertion proves nothing — the ordering is only observable
|      while the command runs, so it is asserted on the sequence of write
|      payloads. A crash between the two writes must leave an .env that still
|      names the key the data was encrypted with.
|   2. APP_KEY IS NEVER TOUCHED. Byte-identical before and after, on every path.
|      It is the fallback that keeps every pre-adoption row readable; a rewrite
|      that "only reformatted" it is silent data loss.
|   3. --show WRITES NOTHING. It is the sanctioned printing path, and an
|      accidental write there would rotate a production key from a command that
|      reads as read-only.
|   4. A PRODUCTION-LOOKING ENVIRONMENT REFUSES without --force.
|
| Every test runs against a TEMP .env fixture: the app base path is redirected
| into a scratch directory, so nothing here can reach the testbench skeleton's
| own .env or the repository's.
|
| Helpers carry an `ekc` prefix — a Pest file declares its helpers at global
| scope for the whole process, so bare names collide across files.
|
*/

use Dotenv\Dotenv;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Lvntr\StarterKit\Console\Commands\EncryptionKeyCommand;
use Lvntr\StarterKit\Support\Encryption\DataEncrypterFactory;

require_once __DIR__.'/EncryptionKeyHarness.php';

beforeEach(function (): void {
    $this->ekcBasePath = sys_get_temp_dir().'/sk-encryption-key-'.bin2hex(random_bytes(6));
    mkdir($this->ekcBasePath, 0755, true);

    // Redirect base_path() away from the testbench skeleton so no test in this
    // file can write an .env anyone else reads.
    app()->setBasePath($this->ekcBasePath);

    config([
        'starter-kit.encryption.cipher' => null,
        'app.cipher' => 'AES-256-CBC',
    ]);
});

afterEach(function (): void {
    $path = $this->ekcBasePath ?? null;

    if (is_string($path) && is_dir($path)) {
        foreach ((array) glob($path.'/{,.}*', GLOB_BRACE) as $entry) {
            if (is_string($entry) && is_file($entry)) {
                @unlink($entry);
            }
        }

        @rmdir($path);
    }
});

/*
|--------------------------------------------------------------------------
| 1. Write order — the safety property
|--------------------------------------------------------------------------
*/

it('writes the previous-key list to disk BEFORE the new primary key', function (): void {
    $old = ekcKey('old-dedicated');

    ekcFixture("APP_NAME=Test\nAPP_KEY=".ekcKey('app')."\nDATA_ENCRYPTION_KEY={$old}\n");

    $result = ekcRun();

    expect($result['status'])->toBe(0);

    $after = ekcEnvContents();
    $new = ekcRead($after, DataEncrypterFactory::PRIMARY_ENV_KEY);

    expect($new)->not->toBeNull()
        ->and($new)->not->toBe($old);

    // TWO flushes, not one merged write: a single write cannot express the
    // ordering guarantee at all.
    expect($result['files']->writes)->toHaveCount(2);

    [$first, $second] = $result['files']->writes;

    // Write #1 — the old key is already preserved, and the primary is STILL the
    // old key. This is the state a crash between the two writes leaves behind,
    // and it is fully readable.
    expect(ekcRead($first, DataEncrypterFactory::PREVIOUS_ENV_KEY))->toBe($old)
        ->and(ekcRead($first, DataEncrypterFactory::PRIMARY_ENV_KEY))->toBe($old)
        ->and($first)->not->toContain((string) $new);

    // Write #2 — only now does the new primary appear, and the preserved list
    // survives it.
    expect(ekcRead($second, DataEncrypterFactory::PRIMARY_ENV_KEY))->toBe($new)
        ->and(ekcRead($second, DataEncrypterFactory::PREVIOUS_ENV_KEY))->toBe($old);

    // End state matches the last write.
    expect($after)->toBe($second);
});

it('prepends the retired key ahead of the keys already in the list, without duplicating', function (): void {
    $old = ekcKey('old-dedicated');
    $older = ekcKey('older');

    // The list is QUOTED because it carries spaces, and phpdotenv rejects
    // whitespace in an unquoted value — an unquoted form here would be an .env
    // that Laravel itself cannot boot, so the command is right to refuse it and
    // the fixture would be testing an unreachable state. The spaces, the blank
    // entry and the duplicate are the point of the test and all survive quoting.
    ekcFixture(
        'APP_KEY='.ekcKey('app')."\n"
        ."DATA_ENCRYPTION_KEY={$old}\n"
        ."DATA_ENCRYPTION_PREVIOUS_KEYS=\"{$older}, {$old} ,,{$older}\"\n"
    );

    expect(ekcRun()['status'])->toBe(0);

    // Newest retired key first (most likely decrypt hit tried earliest); blanks
    // dropped; exact duplicates removed so a re-run cannot grow the list without
    // bound.
    expect(ekcRead(ekcEnvContents(), DataEncrypterFactory::PREVIOUS_ENV_KEY))
        ->toBe($old.','.$older);
});

it('preserves APP_KEY into the previous-key list on first adoption', function (): void {
    $appKey = ekcKey('app');

    ekcFixture("APP_NAME=Test\nAPP_KEY={$appKey}\n");

    $result = ekcRun();

    expect($result['status'])->toBe(0);

    $after = ekcEnvContents();

    expect(ekcRead($after, DataEncrypterFactory::PREVIOUS_ENV_KEY))->toBe($appKey)
        ->and($result['output'])->toContain('Previous key preserved from')
        ->and($result['output'])->toContain('APP_KEY');

    // Same ordering guarantee on the adoption path: before the first write the
    // file has no dedicated key at all, and the first flush must not introduce
    // one.
    expect($result['files']->writes)->toHaveCount(2)
        ->and(ekcRead($result['files']->writes[0], DataEncrypterFactory::PRIMARY_ENV_KEY))->toBeNull()
        ->and(ekcRead($result['files']->writes[0], DataEncrypterFactory::PREVIOUS_ENV_KEY))->toBe($appKey);
});

it('appends the encryption block exactly once when neither key is present in .env', function (): void {
    ekcFixture("APP_NAME=Test\nAPP_KEY=".ekcKey('app')."\n");

    expect(ekcRun()['status'])->toBe(0);

    $after = ekcEnvContents();

    expect(substr_count($after, '# ---- Encryption ----'))->toBe(1)
        ->and(ekcRead($after, DataEncrypterFactory::PRIMARY_ENV_KEY))->not->toBeNull()
        ->and(ekcRead($after, DataEncrypterFactory::PREVIOUS_ENV_KEY))->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| 2. APP_KEY is never touched
|--------------------------------------------------------------------------
*/

it('leaves every APP_KEY line byte-identical across a rotation', function (): void {
    $appKey = ekcKey('app');

    $before = "APP_NAME=Test\nAPP_KEY={$appKey}\n# APP_KEY=base64:commented-out-old\nDATA_ENCRYPTION_KEY=".ekcKey('old')."\n";

    ekcFixture($before);

    expect(ekcRun()['status'])->toBe(0);

    $after = ekcEnvContents();

    expect(ekcAppKeyLines($after))->toBe(ekcAppKeyLines($before))
        ->and(ekcAppKeyLines($after))->toBe(["APP_KEY={$appKey}", '# APP_KEY=base64:commented-out-old'])
        ->and($after)->toContain("APP_KEY={$appKey}\n");
});

/*
|--------------------------------------------------------------------------
| 3. --show writes nothing
|--------------------------------------------------------------------------
*/

it('--show prints a usable key and writes nothing at all', function (): void {
    $before = "APP_NAME=Test\nAPP_KEY=".ekcKey('app')."\nDATA_ENCRYPTION_KEY=".ekcKey('old')."\n";

    ekcFixture($before);

    $result = ekcRun(['--show' => true]);

    expect($result['status'])->toBe(0)
        // No write of any kind reached the filesystem.
        ->and($result['files']->writes)->toBe([])
        ->and(ekcEnvContents())->toBe($before);

    $printed = trim($result['output']);

    expect($printed)->toStartWith('base64:')
        ->and(strlen((string) base64_decode(substr($printed, 7), true)))->toBe(32);
});

it('--show works in a production-looking environment because it mutates nothing', function (): void {
    $before = 'APP_KEY='.ekcKey('app')."\n";

    ekcFixture($before);

    app()->instance('env', 'production');

    $result = ekcRun(['--show' => true]);

    expect($result['status'])->toBe(0)
        ->and($result['files']->writes)->toBe([])
        ->and(ekcEnvContents())->toBe($before);
});

/*
|--------------------------------------------------------------------------
| 4. Production refuses without --force
|--------------------------------------------------------------------------
*/

it('refuses to rotate in a production-looking environment without --force', function (string $environment): void {
    $before = 'APP_KEY='.ekcKey('app')."\nDATA_ENCRYPTION_KEY=".ekcKey('old')."\n";

    ekcFixture($before);

    app()->instance('env', $environment);

    $result = ekcRun();

    expect($result['status'])->toBe(1)
        ->and($result['files']->writes)->toBe([])
        ->and(ekcEnvContents())->toBe($before)
        ->and($result['output'])->toContain('looks like production');
})->with(['production', 'prod', 'prod-eu', 'my-prod']);

it('rotates in a production-looking environment once --force is given', function (): void {
    $old = ekcKey('old');

    ekcFixture('APP_KEY='.ekcKey('app')."\nDATA_ENCRYPTION_KEY={$old}\n");

    app()->instance('env', 'production');

    $result = ekcRun(['--force' => true]);

    expect($result['status'])->toBe(0)
        ->and($result['files']->writes)->toHaveCount(2)
        ->and(ekcRead(ekcEnvContents(), DataEncrypterFactory::PREVIOUS_ENV_KEY))->toBe($old);
});

/*
|--------------------------------------------------------------------------
| 5. Refusals that must not half-write
|--------------------------------------------------------------------------
*/

it('refuses when there is no .env to rotate, and creates none', function (): void {
    $result = ekcRun();

    expect($result['status'])->toBe(1)
        ->and($result['files']->writes)->toBe([])
        ->and(file_exists(ekcEnvPath()))->toBeFalse()
        ->and($result['output'])->toContain('sk:install');
});

it('refuses an unsupported cipher before generating or writing anything', function (): void {
    $before = 'APP_KEY='.ekcKey('app')."\n";

    ekcFixture($before);

    config(['starter-kit.encryption.cipher' => 'AES-999-XYZ']);

    $result = ekcRun();

    expect($result['status'])->toBe(1)
        ->and($result['files']->writes)->toBe([])
        ->and(ekcEnvContents())->toBe($before)
        ->and($result['output'])->toContain('AES-999-XYZ');
});

/*
|--------------------------------------------------------------------------
| 8. File identity — the .env must survive the write as itself
|--------------------------------------------------------------------------
|
| A temp-file-plus-rename write replaces the inode. On the one file this
| feature exists to protect that is not a detail: a shared-env deploy layout
| (Envoyer/Deployer/Capistrano) symlinks .env into each release, and replacing
| the link writes the new key into a directory the next deploy discards — the
| operator then rekeys every row onto a key that vanishes. The mode case is the
| quieter half: a hardened 0600 .env coming back 0644 widens who can read the
| key that just landed in it.
|
*/

it('writes through a symlinked .env instead of replacing the link', function (): void {
    $shared = $this->ekcBasePath.'/shared';
    mkdir($shared, 0755, true);

    $realEnv = $shared.'/.env';
    file_put_contents($realEnv, 'APP_KEY='.ekcKey('app')."\n");

    symlink($realEnv, ekcEnvPath());

    $result = ekcRun();

    expect($result['status'])->toBe(0)
        ->and(is_link(ekcEnvPath()))->toBeTrue()
        ->and(readlink(ekcEnvPath()))->toBe($realEnv)
        ->and(ekcRead((string) file_get_contents($realEnv), DataEncrypterFactory::PRIMARY_ENV_KEY))->not->toBeNull();

    @unlink(ekcEnvPath());
    @unlink($realEnv);
    @rmdir($shared);
});

it('preserves the .env permission bits across a rotation', function (): void {
    ekcFixture('APP_KEY='.ekcKey('app')."\n");
    chmod(ekcEnvPath(), 0600);

    $result = ekcRun();

    expect($result['status'])->toBe(0)
        ->and(fileperms(ekcEnvPath()) & 0777)->toBe(0600);
});

it('rotates a WRITE-LESS .env instead of aborting on the durability flush', function (int $mode): void {
    // 0400/0440 is the mode a hardened deploy actually ships, and it is the one
    // mode where the order of restoreIdentity() and flushToDisk() is observable:
    // the flush reopens the temp file `r+`, and POSIX applies the owner class
    // verbatim, so a mode without the owner-write bit makes that fopen() fail
    // for every non-root owner. Restoring identity BEFORE the flush therefore
    // aborted the rotation on precisely the permissions the kit recommends.
    // The flush now runs while the file still carries the 0600 narrowOrFail()
    // proved; the mode below is what the rename must still land.
    ekcFixture('APP_KEY='.ekcKey('app')."\n");
    chmod(ekcEnvPath(), $mode);

    $result = ekcRun();

    clearstatcache(true, ekcEnvPath());

    expect($result['status'])->toBe(0)
        ->and($result['output'])->not->toContain('reopened to flush')
        ->and(fileperms(ekcEnvPath()) & 0777)->toBe($mode)
        ->and(ekcRead(ekcEnvContents(), DataEncrypterFactory::PRIMARY_ENV_KEY))->not->toBeNull()
        ->and(ekcRead(ekcEnvContents(), DataEncrypterFactory::PREVIOUS_ENV_KEY))->toBe(ekcKey('app'));
})->with([
    'owner read-only, 0400' => 0400,
    'owner+group read-only, 0440' => 0440,
])->skip(
    fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0,
    'Running as root, which can open a read-only file r+ regardless of the ordering under test.',
);

/*
|--------------------------------------------------------------------------
| 9. A retired key must MEAN the same thing on the next boot
|--------------------------------------------------------------------------
|
| The previous-key list is read through phpdotenv (quotes stripped, `${VAR}`
| resolved) and written back UNQUOTED. An entry carrying `#`, `$` or whitespace
| therefore comes back as a DIFFERENT key on the next boot — `#` opens a
| comment and truncates it — and the rows encrypted with it are unreadable with
| no copy of the key anywhere. Only the current primary used to be normalised;
| the entries already in the list rode through verbatim.
|
| The write itself is the other half: put() reports a full disk by RETURNING a
| short byte count, and renaming that temp file into place replaces a complete
| .env with a truncated one — on step (3) that is the only copy of the key being
| retired.
|
*/

/**
 * A Filesystem that lands only part of the body and says so, the way a full
 * disk does. Extends the recorder so the payloads stay observable.
 */
final class EkcShortWriteFilesystem extends EkcRecordingFilesystem
{
    public function put($path, $contents, $lock = false)
    {
        $this->writes[] = (string) $contents;

        // Deliberately NOT parent::put(): the point is a partial body on disk
        // paired with a return value that admits it.
        return file_put_contents($path, substr((string) $contents, 0, 10));
    }
}

it('re-encodes an existing previous key that .env parsing would mangle', function (): void {
    // 32 raw bytes carrying the three characters an unquoted .env line changes
    // the meaning of: a comment opener, an interpolation sigil, whitespace.
    $raw = 'ab #$ '.substr(str_repeat('abcdefghij', 4), 0, 26);

    expect(strlen($raw))->toBe(32);

    // Single-quoted in the fixture, so phpdotenv hands the command the literal
    // bytes — the state an operator who quoted their key is actually in.
    ekcFixture(
        'APP_KEY='.ekcKey('app')."\n"
        .'DATA_ENCRYPTION_KEY='.ekcKey('primary')."\n"
        ."DATA_ENCRYPTION_PREVIOUS_KEYS='".$raw."'\n"
    );

    $result = ekcRun();

    expect($result['status'])->toBe(0);

    // Re-read the written file the way the APPLICATION will: if the entry no
    // longer survives this parse, the key is gone.
    $parsed = Dotenv::parse(ekcEnvContents());

    $entries = explode(',', (string) ($parsed[DataEncrypterFactory::PREVIOUS_ENV_KEY] ?? ''));

    expect($entries)->toHaveCount(2)
        ->and($entries[0])->toBe(ekcKey('primary'))
        ->and($entries[1])->toStartWith('base64:')
        ->and(base64_decode(substr($entries[1], 7), true))->toBe($raw);
});

it('leaves an already env-safe previous key exactly as it found it', function (): void {
    ekcFixture(
        'APP_KEY='.ekcKey('app')."\n"
        .'DATA_ENCRYPTION_KEY='.ekcKey('primary')."\n"
        .'DATA_ENCRYPTION_PREVIOUS_KEYS='.ekcKey('retired')."\n"
    );

    $result = ekcRun();

    $parsed = Dotenv::parse(ekcEnvContents());

    expect($result['status'])->toBe(0)
        ->and(explode(',', (string) $parsed[DataEncrypterFactory::PREVIOUS_ENV_KEY]))
        ->toBe([ekcKey('primary'), ekcKey('retired')]);
});

it('refuses to replace .env when the temporary file was written short', function (): void {
    $before = 'APP_KEY='.ekcKey('app')."\n"
        .'DATA_ENCRYPTION_KEY='.ekcKey('primary')."\n";

    ekcFixture($before);

    $result = ekcRun([], new EkcShortWriteFilesystem);

    // The temp file is deleted on the way out, so the directory holds the
    // untouched .env and nothing else.
    $leftovers = array_values(array_filter(
        (array) scandir($this->ekcBasePath),
        static fn (string $entry): bool => str_starts_with($entry, '.env.') || str_starts_with($entry, '.env.tmp'),
    ));

    expect($result['status'])->toBe(1)
        ->and(ekcEnvContents())->toBe($before)
        ->and($result['output'])->toContain('written short')
        ->and($leftovers)->toBe([]);
});

/*
|--------------------------------------------------------------------------
| 10. The identity and durability checks FAIL CLOSED
|--------------------------------------------------------------------------
|
| Both used to warn and rename anyway, which is the worse of the two outcomes:
| the operator who cannot chown is exactly the deploy user who CAN rename into
| the directory, so the warning shipped a .env the service could no longer read
| while the command reported success; and a flush that silently did nothing
| turns the two-write ordering back into a call order, which is the one failure
| this command exists to prevent.
|
| Neither condition is reachable from a normal test process — handing a file to
| another user is root-only, and a filesystem that refuses fsync is not
| something a test can mount. So the guards are driven directly, against real
| files. The end-to-end "nothing was renamed" half is already covered by the
| short-write case above: every one of these throws on the same path, before the
| rename.
|
*/

it('refuses to replace .env when the ownership could not be handed back', function (): void {
    $temp = $this->ekcBasePath.'/.env.tmp-ownership';
    file_put_contents($temp, 'APP_KEY='.ekcKey('app')."\n");
    chmod($temp, 0600);

    // uid/gid 0 stands in for "the .env belongs to another user": the test
    // process is unprivileged, so the chown inside restoreIdentity cannot take.
    expect(fn () => ekcInvoke('restoreIdentity', [$temp, ekcEnvPath(), 0600, 0, 0]))
        ->toThrow(RuntimeException::class, 'ownership could not be handed back');

    @unlink($temp);
})->skip(
    fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0,
    'Running as root, which CAN hand the file over — the failure under test cannot occur.',
);

it('refuses to replace .env when the replacement cannot be flushed to disk', function (): void {
    // A path with no file behind it cannot be reopened for the flush — the same
    // branch a filesystem that refuses the handle takes.
    expect(fn () => ekcInvoke('flushToDisk', [$this->ekcBasePath.'/.env.tmp-missing', ekcEnvPath()]))
        ->toThrow(RuntimeException::class, 'could not be reopened to flush it to disk');
});

it('restores an identity it CAN restore without complaining', function (): void {
    $temp = $this->ekcBasePath.'/.env.tmp-identity';
    file_put_contents($temp, 'APP_KEY='.ekcKey('app')."\n");
    chmod($temp, 0600);

    // The process's own uid/gid: exactly the case a normal rotation is in.
    ekcInvoke('restoreIdentity', [$temp, ekcEnvPath(), 0640, fileowner($temp), filegroup($temp)]);

    clearstatcache(true, $temp);

    expect(fileperms($temp) & 0777)->toBe(0640);

    @unlink($temp);
});

/*
|--------------------------------------------------------------------------
| 11. The file-specific ACL is part of the identity too
|--------------------------------------------------------------------------
|
| Mode, owner and group are only the POSIX third of "who can read .env". An
| operator who granted the web user read on a 0600 .env with `setfacl -m
| u:www-data:r` holds that grant in a file-specific ACL, and a temp-file rename
| drops it the same way it drops the mode — except invisibly: fileperms() reads
| 0600 before AND after, so every check above stays green while the service
| loses access at the exact moment its key changed.
|
| The design hinges on THREE distinguishable answers, and the tests below pin
| each one: null (this host cannot say — change nothing, or a container with no
| `getfacl` starts refusing every rotation), '' (tooling answered: there is no
| ACL — change nothing), and a non-empty ACL (carry it over, verify it, refuse
| if it did not take). Only the round-trip needs real tooling; the refusal path
| is driven with an ACL no tool on any platform can parse, so it runs
| everywhere — including on a runner where the reason for the failure is that
| the tool is missing entirely.
|
*/

it('carries a file-specific ACL from the replaced .env onto the replacement', function (): void {
    ekcFixture('APP_KEY='.ekcKey('app')."\n");
    chmod(ekcEnvPath(), 0600);

    if (! ekcGrantAcl(ekcEnvPath())) {
        $this->markTestSkipped('No working ACL tooling on this host — the null path is what runs here.');
    }

    $before = ekcAcl(ekcEnvPath());

    // Capture the mode AFTER the ACL is granted, never a literal 0600: a POSIX
    // ACL carries a mask, and setfacl projects that mask onto the group bits, so
    // on Linux the file is 0640 here even though nothing widened it on purpose.
    // Asserting 0600 would pin macOS behaviour onto every ACL-capable host.
    clearstatcache(true, ekcEnvPath());
    $modeBefore = fileperms(ekcEnvPath()) & 0777;

    // Two writes happen (previous list, then primary), so this also proves the
    // ACL is re-captured from the file the FIRST rename installed.
    $result = ekcRun();

    clearstatcache(true, ekcEnvPath());

    expect($before)->not->toBeNull()->and($before)->not->toBe('');

    expect($result['status'])->toBe(0)
        ->and(ekcAcl(ekcEnvPath()))->toBe($before)
        ->and(fileperms(ekcEnvPath()) & 0777)->toBe($modeBefore)
        ->and(ekcRead(ekcEnvContents(), DataEncrypterFactory::PRIMARY_ENV_KEY))->not->toBeNull()
        ->and(ekcRead(ekcEnvContents(), DataEncrypterFactory::PREVIOUS_ENV_KEY))->toBe(ekcKey('app'));
});

it('rotates a .env that carries no file-specific ACL without inventing one', function (): void {
    ekcFixture('APP_KEY='.ekcKey('app')."\n");
    chmod(ekcEnvPath(), 0600);

    $before = ekcAcl(ekcEnvPath());

    $result = ekcRun();

    clearstatcache(true, ekcEnvPath());

    // Identical on every host: '' where tooling exists and the file has no ACL,
    // null where it does not. Either way the rotation must not change it.
    expect($result['status'])->toBe(0)
        ->and(ekcAcl(ekcEnvPath()))->toBe($before)
        ->and(fileperms(ekcEnvPath()) & 0777)->toBe(0600);
});

it('answers "unknown" rather than "no ACL" for a file the tooling cannot read', function (): void {
    // The distinction the whole feature rests on: an unreadable answer must not
    // collapse into "there is no ACL", or a rotation would drop one it never saw.
    expect(ekcAcl($this->ekcBasePath.'/.env.no-such-file'))->toBeNull();
});

it('refuses to replace .env when its file-specific ACL cannot be carried over', function (): void {
    ekcFixture('APP_KEY='.ekcKey('app')."\n");

    $temp = $this->ekcBasePath.'/.env.tmp-acl-refusal';
    file_put_contents($temp, 'APP_KEY='.ekcKey('app')."\n");
    chmod($temp, 0600);

    // An ACL no tool on any platform parses, so the reapplication fails for a
    // real reason on every runner — and on one with no ACL tool at all it fails
    // because the tool is missing, which must refuse just the same.
    expect(fn () => ekcInvoke('carryOverAcl', [$temp, ekcEnvPath(), 'sk::not::an::acl', false]))
        ->toThrow(RuntimeException::class, 'file-specific ACL that could not be carried over');

    @unlink($temp);
});

it('--allow-acl-loss downgrades the ACL refusal to a warning on screen and in the log', function (): void {
    ekcFixture('APP_KEY='.ekcKey('app')."\n");

    $temp = $this->ekcBasePath.'/.env.tmp-acl-allowed';
    file_put_contents($temp, 'APP_KEY='.ekcKey('app')."\n");
    chmod($temp, 0600);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(static fn (string $message): bool => str_contains($message, 'WITHOUT its file-specific ACL'));

    $output = ekcInvoke('carryOverAcl', [$temp, ekcEnvPath(), 'sk::not::an::acl', true]);

    // Deliberate loss stays possible; silent loss does not — the operator is
    // told on screen AND left an audit line after the terminal is closed.
    expect($output)->toContain('was NOT carried over');

    @unlink($temp);
});

it('changes nothing when there is no ACL to carry over, or none could be read', function (): void {
    ekcFixture('APP_KEY='.ekcKey('app')."\n");

    $temp = $this->ekcBasePath.'/.env.tmp-acl-noop';
    file_put_contents($temp, 'APP_KEY='.ekcKey('app')."\n");
    chmod($temp, 0600);

    $before = ekcAcl($temp);

    // null = the host cannot say, '' = it said there is none. Neither may abort
    // and neither may touch the file: this is the path a stock Linux container
    // without the acl package takes on every single rotation.
    ekcInvoke('carryOverAcl', [$temp, ekcEnvPath(), null, false]);
    ekcInvoke('carryOverAcl', [$temp, ekcEnvPath(), '', false]);

    expect(ekcAcl($temp))->toBe($before)
        ->and(fileperms($temp) & 0777)->toBe(0600);

    @unlink($temp);
});

it('lets --allow-acl-loss push a real ACL loss through the FULL rotation instead of refusing it', function (): void {
    // Every test above drives carryOverAcl() directly, for the reason the
    // section header explains: forcing the tool to fail from OUTSIDE the
    // process is not reachable on a normal run. Shadowing PATH is what makes
    // it reachable — the WRITE half of the real tool fails for a real reason,
    // on a REAL ACL, through the full command, not through a hand-invoked
    // private method.
    ekcFixture('APP_KEY='.ekcKey('app')."\n");
    chmod(ekcEnvPath(), 0600);

    if (! ekcGrantAcl(ekcEnvPath())) {
        $this->markTestSkipped('No working ACL tooling on this host — the null path is what runs here.');
    }

    $shadow = ekcShadowAclWriteTool($this->ekcBasePath.'/acl-shadow-bin');

    if ($shadow === null) {
        $this->markTestSkipped('This platform has no ACL write tool for carryOverAcl() to shadow.');
    }

    ekcWithPath($shadow, function (): void {
        // Without the flag: the shadowed tool makes the real carry-over fail,
        // and the FULL command refuses — the same refusal the direct-invoke
        // test above pins, this time reached end to end.
        $before = ekcEnvContents();

        $refused = ekcRun();

        // The rename never happens — carryOverAcl() throws before it — so the
        // real target is untouched even though a temp file was written and
        // flushed on the way there.
        expect($refused['status'])->toBe(1)
            ->and(ekcEnvContents())->toBe($before)
            ->and(ekcRead(ekcEnvContents(), DataEncrypterFactory::PRIMARY_ENV_KEY))->toBeNull()
            ->and($refused['output'])->toContain('file-specific ACL that could not be carried over');

        Log::spy();

        // With the flag: the same real ACL loss downgrades to a warning and
        // the rotation completes — the assertion below is what catches a
        // regression that turned --allow-acl-loss back into a no-op.
        $result = ekcRun(['--allow-acl-loss' => true]);

        expect($result['status'])->toBe(0)
            ->and($result['output'])->toContain('was NOT carried over')
            ->and(ekcRead(ekcEnvContents(), DataEncrypterFactory::PRIMARY_ENV_KEY))->not->toBeNull()
            ->and(ekcRead(ekcEnvContents(), DataEncrypterFactory::PREVIOUS_ENV_KEY))->toBe(ekcKey('app'));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'WITHOUT its file-specific ACL'))
            ->once();
    });
});

it('leaves a full rotation unchanged when --allow-acl-loss is given but there is no ACL to lose', function (): void {
    // The common case in CI: no ACL tooling, or a file that never carried one.
    // --allow-acl-loss must be a pure no-op here — carryOverAcl() returns on
    // the null/'' branch before the flag is even consulted.
    ekcFixture('APP_KEY='.ekcKey('app')."\n");
    chmod(ekcEnvPath(), 0600);

    Log::spy();

    $result = ekcRun(['--allow-acl-loss' => true]);

    expect($result['status'])->toBe(0)
        ->and($result['output'])->not->toContain('NOT carried over')
        ->and(fileperms(ekcEnvPath()) & 0777)->toBe(0600)
        ->and(ekcRead(ekcEnvContents(), DataEncrypterFactory::PRIMARY_ENV_KEY))->not->toBeNull();

    Log::shouldNotHaveReceived('warning');
});

it('offers --allow-acl-loss on the command signature, worded for BOTH directions', function (): void {
    $definition = (new EncryptionKeyCommand(new Filesystem))->getDefinition();

    $description = $definition->getOption('allow-acl-loss')->getDescription();

    // The flag covers a grant that could not be CARRIED OVER and one that was
    // INHERITED and could not be cleared. Help text that names only the first
    // sends an operator hunting for a second flag that does not exist.
    expect($definition->hasOption('allow-acl-loss'))->toBeTrue()
        ->and($description)->toContain('ACL')
        ->and($description)->toContain('carried over')
        ->and($description)->toContain('inherited');
});

/*
|--------------------------------------------------------------------------
| 12. …and the ACL mirror: what the replacement grants that .env does not
|--------------------------------------------------------------------------
|
| Section 11 asks whether the replacement KEEPS what .env granted. This one asks
| the mirror question, which has a worse failure mode: does the replacement GRANT
| something .env never did?
|
| The temp file is created in .env's own directory, so a directory-level
| inheritance rule (`setfacl -d -m u:deploy:r`, `chmod +a "… file_inherit"`)
| puts an entry on it that the target never had. Nothing above sees it:
| narrowOrFail() proves `fileperms() & 0077 === 0` and that stays TRUE — a POSIX
| mode does not describe an ACL — and carryOverAcl() returns early for a target
| with no ACL, without ever looking at the temp. The rename then installs the
| inherited grant as the new .env and the named account can read the key that
| was just rotated in, with `0600` on screen before and after.
|
| The write-time assertion below is the one that matters: the ACL is corrected
| BEFORE key material enters the file, not afterwards, so there is no window in
| which the inherited principal could read the key out of the temp file.
|
*/

/**
 * Records the ACL each candidate .env body's target carried AT THE MOMENT put()
 * was called — before a single byte of it was written.
 *
 * End-state assertions cannot distinguish "normalised before the key was
 * written" from "normalised after", and those are the two orders this feature
 * exists to choose between. The ordering is only observable while the command
 * runs, so it is captured here, exactly as the write-ORDER tests capture the
 * payload sequence.
 */
final class EkcAclAtWriteFilesystem extends EkcRecordingFilesystem
{
    /** @var list<string|null> */
    public array $aclsAtWrite = [];

    public function put($path, $contents, $lock = false)
    {
        $this->aclsAtWrite[] = ekcAcl((string) $path);

        return parent::put($path, $contents, $lock);
    }
}

it('sheds a directory-inherited ACL from the replacement BEFORE the key is written', function (): void {
    ekcFixture('APP_KEY='.ekcKey('app')."\n");
    chmod(ekcEnvPath(), 0600);

    // The rule goes on the directory AFTER .env exists, so .env itself carries
    // no ACL — '' — while every temp file created next to it is born with one.
    if (! ekcGrantInheritedAcl($this->ekcBasePath)) {
        $this->markTestSkipped('No working directory-inheritance ACL tooling on this host.');
    }

    expect(ekcAcl(ekcEnvPath()))->toBe('');

    $files = new EkcAclAtWriteFilesystem;

    $result = ekcRun([], $files);

    clearstatcache(true, ekcEnvPath());

    expect($result['status'])->toBe(0)
        // The property, in write order: neither candidate body was written into
        // a file that carried the inherited grant.
        ->and($files->aclsAtWrite)->toHaveCount(2)
        ->and($files->aclsAtWrite)->toBe(['', ''])
        // And the end state agrees — the rename installed a file as narrow as
        // the one it replaced, mode AND ACL.
        ->and(ekcAcl(ekcEnvPath()))->toBe('')
        ->and(fileperms(ekcEnvPath()) & 0777)->toBe(0600)
        ->and(ekcRead(ekcEnvContents(), DataEncrypterFactory::PRIMARY_ENV_KEY))->not->toBeNull()
        ->and(ekcRead(ekcEnvContents(), DataEncrypterFactory::PREVIOUS_ENV_KEY))->toBe(ekcKey('app'));
});

it('refuses to write the key into a file whose inherited ACL could not be cleared', function (): void {
    ekcFixture('APP_KEY='.ekcKey('app')."\n");

    $temp = $this->ekcBasePath.'/.env.tmp-acl-inherited';
    touch($temp);
    chmod($temp, 0600);

    // Granted directly rather than through the directory: what normaliseTempAcl()
    // reacts to is an ACL ON THE TEMP FILE, and where it came from is irrelevant
    // to the decision.
    if (! ekcGrantAcl($temp)) {
        $this->markTestSkipped('No working ACL tooling on this host — the null path is what runs here.');
    }

    $shadow = ekcShadowAclWriteTool($this->ekcBasePath.'/acl-clear-shadow-bin');

    if ($shadow === null) {
        $this->markTestSkipped('This platform has no ACL write tool to shadow.');
    }

    // '' is the target's ACL: tooling answered, .env carries none. So the temp's
    // entry is a grant .env does not have, and the shadowed tool cannot clear it.
    ekcWithPath($shadow, function () use ($temp): void {
        expect(fn () => ekcInvoke('normaliseTempAcl', [$temp, ekcEnvPath(), '', false]))
            ->toThrow(RuntimeException::class, 'inherited a file-specific ACL');
    });

    @unlink($temp);
});

it('--allow-acl-loss downgrades the inherited-ACL refusal to a warning on screen and in the log', function (): void {
    ekcFixture('APP_KEY='.ekcKey('app')."\n");

    $temp = $this->ekcBasePath.'/.env.tmp-acl-inherited-allowed';
    touch($temp);
    chmod($temp, 0600);

    if (! ekcGrantAcl($temp)) {
        $this->markTestSkipped('No working ACL tooling on this host — the null path is what runs here.');
    }

    $shadow = ekcShadowAclWriteTool($this->ekcBasePath.'/acl-clear-shadow-allowed-bin');

    if ($shadow === null) {
        $this->markTestSkipped('This platform has no ACL write tool to shadow.');
    }

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(static fn (string $message): bool => str_contains($message, 'INHERITED ACL COULD NOT BE NORMALISED'));

    $output = ekcWithPath($shadow, fn (): string => ekcInvoke('normaliseTempAcl', [$temp, ekcEnvPath(), '', true]));

    // The same escape hatch as the opposite direction, and just as loud: the
    // operator is told on screen AND left an audit line.
    expect($output)->toContain('carries a file-specific ACL that');

    clearstatcache(true, $temp);

    // narrowOrFail() re-runs even on the downgraded path: the operator accepted
    // an ACL they were shown, never a widened mode nobody reported. On Linux
    // this is not theoretical — dropping an ACL hands the mode's group bits back
    // to the `group::` entry.
    expect(fileperms($temp) & 0777)->toBe(0600);

    @unlink($temp);
});

it('leaves a temp file with no ACL, and an unknown target ACL, completely alone', function (): void {
    // The branch EVERY rotation takes on a host without a directory inheritance
    // rule, and the one a container without `getfacl` takes on every rotation.
    // Neither may abort and neither may touch the file — turning "cannot say"
    // into a refusal would break rotation on a stock Linux container.
    ekcFixture('APP_KEY='.ekcKey('app')."\n");

    $temp = $this->ekcBasePath.'/.env.tmp-acl-mirror-noop';
    touch($temp);
    chmod($temp, 0600);

    $before = ekcAcl($temp);

    Log::spy();

    // null target ACL (host cannot say) — no-op even if the temp had an entry.
    ekcInvoke('normaliseTempAcl', [$temp, ekcEnvPath(), null, false]);
    // '' target ACL and a temp with no ACL of its own — nothing to strip.
    ekcInvoke('normaliseTempAcl', [$temp, ekcEnvPath(), '', false]);
    // A target ACL the temp does not mirror, but the temp grants nothing, so it
    // is still narrower than the target: carryOverAcl() owns that direction.
    ekcInvoke('normaliseTempAcl', [$temp, ekcEnvPath(), 'sk::not::an::acl', false]);

    expect(ekcAcl($temp))->toBe($before)
        ->and(fileperms($temp) & 0777)->toBe(0600);

    Log::shouldNotHaveReceived('warning');

    @unlink($temp);
});
