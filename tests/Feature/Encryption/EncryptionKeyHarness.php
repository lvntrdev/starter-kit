<?php

use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Filesystem\Filesystem;
use Lvntr\StarterKit\Console\Commands\EncryptionKeyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Shared harness for the encryption:key tests.
 *
 * The helpers live HERE, in a plain `require_once` file, rather than at the top
 * of one test file: Pest declares a test file's helpers at global scope for the
 * whole process, so a sibling file can call them — but only while both files are
 * collected in the same run. `vendor/bin/pest <one-file>` loads only that file,
 * and every helper call in the sibling became an undefined-function fatal,
 * breaking exactly the targeted run this project's verification discipline asks
 * for.
 *
 * The `ekc` prefix stays for the reason it was chosen: global scope is shared
 * and bare names collide across files.
 */

/**
 * A Filesystem that remembers every payload handed to put(), in order.
 *
 * atomicPut() routes its single write per step through put() (into a sibling
 * temp file) and then renames it into place, so this list IS the ordered
 * sequence of candidate .env bodies the command flushed to disk. Recording the
 * payload rather than re-reading the file is what makes the intermediate state
 * observable at all.
 *
 * Not final: a test that needs the write itself to misbehave (a short put(),
 * the shape a full disk produces) subclasses this so the payloads stay
 * recorded and ekcRun()'s contract still holds.
 */
class EkcRecordingFilesystem extends Filesystem
{
    /** @var list<string> */
    public array $writes = [];

    public function put($path, $contents, $lock = false)
    {
        $this->writes[] = (string) $contents;

        return parent::put($path, $contents, $lock);
    }
}

/**
 * Deterministic 32-byte key material in the `base64:` form an operator writes.
 */
function ekcKey(string $seed): string
{
    return 'base64:'.base64_encode(substr(hash('sha256', $seed, true), 0, 32));
}

function ekcEnvPath(): string
{
    return base_path('.env');
}

/**
 * Install an .env fixture inside the redirected (temp) base path.
 */
function ekcFixture(string $contents): void
{
    file_put_contents(ekcEnvPath(), $contents);
}

function ekcEnvContents(): string
{
    return (string) file_get_contents(ekcEnvPath());
}

/**
 * Every APP_KEY assignment line, verbatim — the unit of the "untouched"
 * assertion. Compared as raw lines on purpose: a value that round-tripped
 * through a re-encode would still parse to the same bytes and must STILL fail.
 *
 * @return list<string>
 */
function ekcAppKeyLines(string $content): array
{
    preg_match_all('%^.*APP_KEY[ \t]*=.*$%m', $content, $matches);

    return $matches[0];
}

/**
 * Read one key's effective value out of an .env body (last assignment wins,
 * matching phpdotenv).
 */
function ekcRead(string $content, string $key): ?string
{
    $pattern = '%^[ \t]*(?:export[ \t]+)?'.preg_quote($key, '%').'[ \t]*=(.*)$%m';

    if (! preg_match_all($pattern, $content, $matches)) {
        return null;
    }

    $value = trim((string) end($matches[1]));

    return $value === '' ? null : $value;
}

/**
 * Call one of the command's private writer helpers directly, against a real file.
 *
 * The identity and durability guards refuse conditions a test process cannot
 * create — handing a file to another user is root-only, and a filesystem that
 * refuses fsync is not something a test can mount — so the guard itself is
 * driven instead of the environment.
 *
 * IO is wired even though most of these helpers never touch it: the
 * --allow-acl-loss branch of carryOverAcl() warns on the console instead of
 * throwing, and that warning is the whole point of the flag, so it has to be
 * observable. The buffer is returned for that one case.
 *
 * @param  list<mixed>  $arguments
 * @return string whatever the helper wrote to the console
 */
function ekcInvoke(string $method, array $arguments): string
{
    $command = new EncryptionKeyCommand(new Filesystem);
    $command->setLaravel(app());

    $input = new ArrayInput([], $command->getDefinition());
    $input->setInteractive(false);

    $buffer = new BufferedOutput;
    $style = new OutputStyle($input, $buffer);

    foreach (['input' => $input, 'output' => $style, 'components' => new Factory($style)] as $property => $value) {
        (new ReflectionProperty($command, $property))->setValue($command, $value);
    }

    (new ReflectionMethod($command, $method))->invokeArgs($command, $arguments);

    return $buffer->fetch();
}

/**
 * A file's file-specific ACL as the command itself reads it — null when this
 * platform or host has no ACL tooling.
 *
 * The ACL tests gate on THIS rather than on `PHP_OS_FAMILY`: a Linux container
 * without the `acl` package, or one with `exec()` disabled, is exactly the host
 * the null path exists for, and a test that assumed tooling there would fail for
 * the reason the feature is designed to tolerate.
 */
function ekcAcl(string $path): ?string
{
    $command = new EncryptionKeyCommand(new Filesystem);

    /** @var string|null $acl */
    $acl = (new ReflectionMethod($command, 'readFileAcl'))->invoke($command, $path);

    return $acl;
}

/**
 * Put a real, file-specific ACL on $path, or return false when the platform
 * cannot. Uses the same tools the command does, from the opposite direction.
 *
 * The grantee is root/uid 0 — the one account that exists on every runner, and
 * the one a name lookup can never fail on. Granting it READ on a scratch file
 * costs nothing: root could read it regardless.
 */
function ekcGrantAcl(string $path): bool
{
    $quoted = escapeshellarg($path);

    $command = match (PHP_OS_FAMILY) {
        // macOS has no setfacl; +a appends one ACE and resolves the name to a UUID.
        'Darwin' => 'chmod +a '.escapeshellarg('root allow read').' '.$quoted.' 2>/dev/null',
        // Numeric qualifier: no name service involved, so a minimal container
        // still produces the extended entry the test needs.
        'Linux' => 'setfacl -m u:0:r -- '.$quoted.' 2>/dev/null',
        default => null,
    };

    if ($command === null) {
        return false;
    }

    $output = [];
    $status = 1;

    @exec($command, $output, $status);

    if ($status !== 0) {
        return false;
    }

    $acl = ekcAcl($path);

    return $acl !== null && $acl !== '';
}

/**
 * Put a directory-level INHERITANCE rule on $dir, so every file created inside
 * it is born carrying an ACL entry — the condition normaliseTempAcl() exists
 * for. Returns false when the platform or host cannot express one.
 *
 * The grantee is root/uid 0 for the same reason ekcGrantAcl() uses it: it is
 * the one account present on every runner and the one no name lookup can fail
 * on, and granting it read on a scratch file costs nothing.
 *
 * Verified by PROBE rather than by the tool's exit status: `setfacl -d` and
 * `chmod +a` both succeed on filesystems that then quietly decline to hand the
 * entry to new files (a tmpfs without ACL support, a mount without `acl`), and
 * a test that assumed inheritance there would fail for a reason that is not the
 * behaviour under test.
 */
function ekcGrantInheritedAcl(string $dir): bool
{
    $quoted = escapeshellarg($dir);

    $command = match (PHP_OS_FAMILY) {
        'Darwin' => 'chmod +a '.escapeshellarg('root allow read,file_inherit').' '.$quoted.' 2>/dev/null',
        'Linux' => 'setfacl -d -m u:0:r -- '.$quoted.' 2>/dev/null',
        default => null,
    };

    if ($command === null) {
        return false;
    }

    $output = [];
    $status = 1;

    @exec($command, $output, $status);

    if ($status !== 0) {
        return false;
    }

    $probe = $dir.'/.ekc-inherit-probe';

    touch($probe);
    chmod($probe, 0600);

    $inherited = ekcAcl($probe);

    @unlink($probe);

    return $inherited !== null && $inherited !== '';
}

/**
 * Shadow the external binary the ACL WRITE half shells out to — carryOverAcl()'s
 * re-apply and normaliseTempAcl()'s clear reach the SAME binary (`chmod` on
 * Darwin, `setfacl` on Linux) — with one that always fails, by
 * prepending a scratch bin directory to PATH. The READ half (`ls -lde` /
 * `getfacl`) is left alone, so readFileAcl() still reports the real ACL —
 * only the re-apply fails, the same way a real tool would on a host where it
 * cannot write (a read-only mount, a hardened `PATH`, a missing package that
 * still ships the read-only counterpart).
 *
 * Returns null on a platform carryOverAcl() has no write tool for at all,
 * since there is then no failure to force — the caller should skip.
 */
function ekcShadowAclWriteTool(string $binDir): ?string
{
    $binary = match (PHP_OS_FAMILY) {
        'Darwin' => 'chmod',
        'Linux' => 'setfacl',
        default => null,
    };

    if ($binary === null) {
        return null;
    }

    mkdir($binDir, 0755, true);

    $script = $binDir.'/'.$binary;
    file_put_contents($script, "#!/bin/sh\nexit 1\n");
    chmod($script, 0755);

    return $binDir;
}

/**
 * Run $callback with PATH temporarily prefixed by $prefix, restoring the
 * original value even if $callback throws or an assertion fails.
 */
function ekcWithPath(string $prefix, callable $callback): mixed
{
    $original = getenv('PATH');

    putenv('PATH='.$prefix.PATH_SEPARATOR.($original === false ? '' : $original));

    try {
        return $callback();
    } finally {
        putenv($original === false ? 'PATH' : 'PATH='.$original);
    }
}

/**
 * Run encryption:key with IO wired by hand so the Filesystem can be injected.
 *
 * Constructed directly rather than resolved through Artisan: the recording
 * Filesystem is the whole point, and binding one into the container would hand
 * it to every other consumer of that class as well.
 *
 * A caller may pass its own recorder to make the write itself misbehave (a
 * short or refused put(), the shape a full disk produces); the default is the
 * plain recorder.
 *
 * @param  array<string, mixed>  $parameters
 * @return array{status: int, output: string, files: EkcRecordingFilesystem}
 */
function ekcRun(array $parameters = [], ?EkcRecordingFilesystem $files = null): array
{
    $files ??= new EkcRecordingFilesystem;

    $command = new EncryptionKeyCommand($files);
    $command->setLaravel(app());

    $definition = $command->getDefinition();

    if (! $definition->hasOption('no-interaction')) {
        $definition->addOption(new InputOption('no-interaction', 'n', InputOption::VALUE_NONE));
    }

    $input = new ArrayInput($parameters, $definition);
    $input->setInteractive(false);

    $buffer = new BufferedOutput;
    $style = new OutputStyle($input, $buffer);

    foreach (['input' => $input, 'output' => $style, 'components' => new Factory($style)] as $property => $value) {
        (new ReflectionProperty($command, $property))->setValue($command, $value);
    }

    return ['status' => $command->handle(), 'output' => $buffer->fetch(), 'files' => $files];
}
