<?php

use Illuminate\Filesystem\Filesystem;
use Lvntr\StarterKit\Console\Commands\InstallCommand;
use Symfony\Component\Console\Input\ArrayInput;

/*
|--------------------------------------------------------------------------
| sk:install publish loop — a consumer edit survives a re-install
|--------------------------------------------------------------------------
|
| Everything outside $preservablePaths used to be copied over unconditionally,
| so running `sk:install` a second time returned every published file to its
| stub contents with nothing in the summary saying so. The registry already
| recorded what the kit SHIPPED for each path, which is exactly the third value
| needed to tell "they never touched it" from "they edited it".
|
| publishDirectory() takes its source and destination as arguments and reads the
| registry from config('starter-kit.published_hashes'), so every case below runs
| against throwaway temp trees — no base_path() writes, nothing to leak into the
| testbench skeleton.
|
| Helpers carry a `pog` prefix: a Pest file declares its helpers at global scope
| for the whole process, so bare names collide across files.
|
*/

/**
 * A Filesystem that records every path handed to put(), so the temp-file +
 * rename discipline is observable: an atomic write must NEVER put() the final
 * path — that is the truncation window the trait exists to close.
 */
final class PogRecordingFilesystem extends Filesystem
{
    /** @var list<string> */
    public array $puts = [];

    public function put($path, $contents, $lock = false)
    {
        $this->puts[] = (string) $path;

        return parent::put($path, $contents, $lock);
    }
}

/**
 * A Filesystem whose put() fails the way a full disk does: false, no exception.
 */
final class PogFailingFilesystem extends Filesystem
{
    public function put($path, $contents, $lock = false)
    {
        return false;
    }
}

/** @param array<string, string> $tree */
function pogTree(string $prefix, array $tree): string
{
    $dir = sys_get_temp_dir().'/sk-publish-'.$prefix.'-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);

    foreach ($tree as $relative => $contents) {
        $path = $dir.'/'.ltrim($relative, '/');
        is_dir(dirname($path)) || mkdir(dirname($path), 0700, true);
        file_put_contents($path, $contents);
    }

    return $dir;
}

function pogRemove(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($dir);
}

/** @param array<string, string> $records */
function pogSeedRegistry(array $records): string
{
    $path = sys_get_temp_dir().'/sk-publish-hashes-'.bin2hex(random_bytes(6)).'.json';
    file_put_contents($path, json_encode(['_format' => 'v2'] + $records));

    config(['starter-kit.published_hashes' => $path]);

    return $path;
}

function pogBootCommand(?Filesystem $files = null): InstallCommand
{
    $command = new InstallCommand;
    // getSkipPaths() reads --without-ai-skill, so the loop needs a bound input.
    $command->setInput(new ArrayInput([], $command->getDefinition()));

    if ($files !== null) {
        $property = new ReflectionProperty($command, 'files');
        $property->setAccessible(true);
        $property->setValue($command, $files);
    }

    return $command;
}

function pogPublish(InstallCommand $command, string $source, string $destination, bool $force = false): void
{
    $method = new ReflectionMethod($command, 'publishDirectory');
    $method->setAccessible(true);
    $method->invoke($command, $source, $destination, $force);
}

/** @return list<string> */
function pogProperty(InstallCommand $command, string $property): array
{
    $p = new ReflectionProperty($command, $property);
    $p->setAccessible(true);

    /** @var list<string> */
    return $p->getValue($command);
}

function pogDecide(string $stub, ?string $target, ?string $recorded, bool $force): string
{
    $method = new ReflectionMethod(InstallCommand::class, 'decidePublishedStub');
    $method->setAccessible(true);

    return $method->invoke(new InstallCommand, $stub, $target, $recorded, $force);
}

const POG_PATH = 'app/Http/Controllers/DemoController.php';

/*
|--------------------------------------------------------------------------
| The pure rule
|--------------------------------------------------------------------------
*/

it('decides each stub outcome from the three hashes alone', function (
    string $stub,
    ?string $target,
    ?string $recorded,
    bool $force,
    string $expected,
): void {
    expect(pogDecide($stub, $target, $recorded, $force))->toBe($expected);
})->with([
    'already current' => ['a', 'a', 'a', false, InstallCommand::STUB_IDENTICAL],
    'already current, even under force' => ['a', 'a', 'old', true, InstallCommand::STUB_IDENTICAL],
    'target absent' => ['a', null, null, false, InstallCommand::STUB_WRITE],
    'force takes the package version' => ['a', 'edited', 'old', true, InstallCommand::STUB_WRITE],
    'install-time opt-out' => ['a', 'edited', '__skipped__', false, InstallCommand::STUB_OPTED_OUT],
    'no record at all' => ['a', 'edited', null, false, InstallCommand::STUB_UNTRACKED],
    're-created after a recorded deletion' => ['a', 'edited', '__deleted__', false, InstallCommand::STUB_UNTRACKED],
    'nothing new shipped, copy differs' => ['a', 'edited', 'a', false, InstallCommand::STUB_UP_TO_DATE],
    'untouched since we shipped it' => ['new', 'old', 'old', false, InstallCommand::STUB_WRITE],
    'new version AND a local edit' => ['new', 'edited', 'old', false, InstallCommand::STUB_MODIFIED],
]);

/*
|--------------------------------------------------------------------------
| The publish loop
|--------------------------------------------------------------------------
*/

it('preserves a consumer-edited file instead of restoring the stub', function (): void {
    $source = pogTree('src', [POG_PATH => 'NEW STUB']);
    $destination = pogTree('dst', [POG_PATH => 'MY EDIT']);
    $registry = pogSeedRegistry([POG_PATH => md5('ORIGINAL STUB')]);

    $command = pogBootCommand();
    pogPublish($command, $source, $destination);

    expect(file_get_contents($destination.'/'.POG_PATH))->toBe('MY EDIT')
        ->and(pogProperty($command, 'preserved'))->toContain(POG_PATH)
        ->and(pogProperty($command, 'published'))->not->toContain(POG_PATH);

    pogRemove($source);
    pogRemove($destination);
    unlink($registry);
});

it('leaves the file alone when the package has shipped nothing new for it', function (): void {
    $source = pogTree('src', [POG_PATH => 'NEW STUB']);
    $destination = pogTree('dst', [POG_PATH => 'MY EDIT']);
    // Recorded hash === the stub we are shipping: there is no new version to
    // offer, so the difference on disk can only be the consumer's own edit.
    $registry = pogSeedRegistry([POG_PATH => md5('NEW STUB')]);

    $command = pogBootCommand();
    pogPublish($command, $source, $destination);

    expect(file_get_contents($destination.'/'.POG_PATH))->toBe('MY EDIT')
        ->and(pogProperty($command, 'preserved'))->toContain(POG_PATH);

    pogRemove($source);
    pogRemove($destination);
    unlink($registry);
});

it('refreshes a provably untouched copy with the current stub', function (): void {
    $source = pogTree('src', [POG_PATH => 'NEW STUB']);
    $destination = pogTree('dst', [POG_PATH => 'ORIGINAL STUB']);
    $registry = pogSeedRegistry([POG_PATH => md5('ORIGINAL STUB')]);

    $command = pogBootCommand();
    pogPublish($command, $source, $destination);

    expect(file_get_contents($destination.'/'.POG_PATH))->toBe('NEW STUB')
        ->and(pogProperty($command, 'published'))->toContain(POG_PATH)
        ->and(pogProperty($command, 'preserved'))->toBeEmpty();

    pogRemove($source);
    pogRemove($destination);
    unlink($registry);
});

it('still overwrites a consumer-edited file under --force', function (): void {
    $source = pogTree('src', [POG_PATH => 'NEW STUB']);
    $destination = pogTree('dst', [POG_PATH => 'MY EDIT']);
    $registry = pogSeedRegistry([POG_PATH => md5('ORIGINAL STUB')]);

    $command = pogBootCommand();
    pogPublish($command, $source, $destination, force: true);

    expect(file_get_contents($destination.'/'.POG_PATH))->toBe('NEW STUB')
        ->and(pogProperty($command, 'preserved'))->toBeEmpty()
        ->and(pogProperty($command, 'published'))->toContain(POG_PATH);

    pogRemove($source);
    pogRemove($destination);
    unlink($registry);
});

it('publishes an untracked existing file, keeping the documented install behaviour', function (): void {
    // No record: nothing to compare against, and the already-installed stop
    // guards the case that matters. Pinned so the scope of the guard is explicit.
    $source = pogTree('src', [POG_PATH => 'NEW STUB']);
    $destination = pogTree('dst', [POG_PATH => 'SOMETHING ELSE']);
    $registry = pogSeedRegistry([]);

    $command = pogBootCommand();
    pogPublish($command, $source, $destination);

    expect(file_get_contents($destination.'/'.POG_PATH))->toBe('NEW STUB')
        ->and(pogProperty($command, 'preserved'))->toBeEmpty();

    pogRemove($source);
    pogRemove($destination);
    unlink($registry);
});

it('does not restore a file the consumer deleted after it was published', function (): void {
    $source = pogTree('src', [POG_PATH => 'NEW STUB']);
    $destination = pogTree('dst', []);
    $registry = pogSeedRegistry([POG_PATH => md5('ORIGINAL STUB')]);

    $command = pogBootCommand();
    pogPublish($command, $source, $destination);

    expect(file_exists($destination.'/'.POG_PATH))->toBeFalse()
        ->and(pogProperty($command, 'skipped'))->toContain(POG_PATH);

    pogRemove($source);
    pogRemove($destination);
    unlink($registry);
});

/*
|--------------------------------------------------------------------------
| Atomic registry / checkpoint writes
|--------------------------------------------------------------------------
*/

it('writes the hash registry through a temp file and a rename, never in place', function (): void {
    $dir = sys_get_temp_dir().'/sk-publish-reg-'.bin2hex(random_bytes(6));
    $registryPath = $dir.'/nested/hashes.json';
    config(['starter-kit.published_hashes' => $registryPath]);

    $files = new PogRecordingFilesystem;
    $command = pogBootCommand($files);

    $method = new ReflectionMethod($command, 'writeHashRegistry');
    $method->setAccessible(true);
    $method->invoke($command, ['app/Foo.php' => md5('x')]);

    expect(json_decode(file_get_contents($registryPath), true))
        ->toBe(['app/Foo.php' => md5('x')])
        // The registry directory is created on demand, and the only leftover in
        // it is the registry itself — the temp file was renamed, not abandoned.
        ->and(array_values(array_diff(scandir(dirname($registryPath)), ['.', '..'])))
        ->toBe(['hashes.json'])
        ->and($files->puts)->not->toContain($registryPath)
        ->and($files->puts)->toHaveCount(1);

    pogRemove($dir);
});

it('fails the write instead of renaming a truncated temp file into place', function (): void {
    $dir = sys_get_temp_dir().'/sk-publish-reg-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    $registryPath = $dir.'/hashes.json';
    file_put_contents($registryPath, '{"kept":"yes"}');
    config(['starter-kit.published_hashes' => $registryPath]);

    $command = pogBootCommand(new PogFailingFilesystem);

    $method = new ReflectionMethod($command, 'writeHashRegistry');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($command, ['app/Foo.php' => md5('x')]))
        ->toThrow(RuntimeException::class)
        // The previous registry is untouched and no temp file survives.
        ->and(file_get_contents($registryPath))->toBe('{"kept":"yes"}')
        ->and(array_values(array_diff(scandir($dir), ['.', '..'])))->toBe(['hashes.json']);

    pogRemove($dir);
});

it('writes the resume checkpoint through the same atomic path', function (): void {
    $files = new PogRecordingFilesystem;
    $command = pogBootCommand($files);

    $pathMethod = new ReflectionMethod($command, 'progressFilePath');
    $pathMethod->setAccessible(true);
    $progressPath = $pathMethod->invoke($command);

    $persist = new ReflectionMethod($command, 'persistProgress');
    $persist->setAccessible(true);
    $persist->invoke($command);

    expect($files->puts)->not->toContain($progressPath)
        ->and(json_decode(file_get_contents($progressPath), true))
        ->toHaveKeys(['completed', 'meta', 'updated_at']);

    unlink($progressPath);
});
