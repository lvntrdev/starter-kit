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
