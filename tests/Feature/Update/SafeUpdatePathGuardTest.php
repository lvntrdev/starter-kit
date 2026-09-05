<?php

use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory as ComponentsFactory;
use Illuminate\Filesystem\Filesystem;
use Lvntr\StarterKit\Console\Commands\UpdateCommand;
use Lvntr\StarterKit\StarterKitServiceProvider;
use Lvntr\StarterKit\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

// Feature/Update is not bound to a TestCase in Pest.php — bind at file scope so
// base_path()/config() resolve against the testbench sandbox app.
uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| SAFE_UPDATE_PATHS — a package-owned file is refreshed, never clobbered
|--------------------------------------------------------------------------
|
| `app/Enums/PermissionEnum.php` is the only entry in SAFE_UPDATE_PATHS, and it
| is a backed enum with public for()/allFor() helpers — adding a project ability
| (`case Approve = 'approve';`) is the obvious thing for a consumer to do. The
| loop used to copy the stub over it whenever the two files differed at all, so
| that case vanished on the next `sk:update` with nothing in the summary saying
| so, and the loss only surfaced later as permissions that stopped seeding.
|
| These tests run the REAL private updateSafePaths() against a real stub and pin
| the four outcomes of its decision rule: refresh an untouched copy, preserve an
| edited one, ask about an untracked one, and obey --force.
|
*/

function bootSafePathUpdateCommand(): UpdateCommand
{
    $command = new UpdateCommand;

    $filesProperty = new ReflectionProperty($command, 'files');
    $filesProperty->setValue($command, new Filesystem);

    $buffer = new BufferedOutput;
    $style = new OutputStyle(new ArrayInput([], $command->getDefinition()), $buffer);
    $command->setInput(new ArrayInput([], $command->getDefinition()));
    $command->setOutput($style);

    $componentsProperty = new ReflectionProperty($command, 'components');
    $componentsProperty->setValue($command, new ComponentsFactory($style));

    return $command;
}

function runSafePathUpdate(UpdateCommand $command, bool $force = false): void
{
    $method = new ReflectionMethod($command, 'updateSafePaths');
    $method->invoke($command, $force, false);
}

/** @return list<string> */
function readSafePathProperty(UpdateCommand $command, string $property): array
{
    $p = new ReflectionProperty($command, $property);

    /** @var list<string> */
    return $p->getValue($command);
}

function seedSafePathHashRegistry(array $records): string
{
    $fs = new Filesystem;
    $hashFile = sys_get_temp_dir().'/sk_safe_path_hashes_'.uniqid('', true).'.json';
    $fs->put($hashFile, json_encode(['_format' => 'v2'] + $records));

    config(['starter-kit.published_hashes' => $hashFile]);

    return $hashFile;
}

function safePathRelative(): string
{
    return 'app/Enums/PermissionEnum.php';
}

function safePathStubContents(): string
{
    return (new Filesystem)->get(StarterKitServiceProvider::stubsPath(safePathRelative()));
}

function writeSafePathTarget(string $contents): void
{
    $fs = new Filesystem;
    $fs->ensureDirectoryExists(dirname(base_path(safePathRelative())));
    $fs->put(base_path(safePathRelative()), $contents);
}

afterEach(function (): void {
    $fs = new Filesystem;
    $target = base_path(safePathRelative());

    if ($fs->exists($target)) {
        $fs->delete($target);
    }
});

it('refreshes a provably unmodified copy with the current stub', function (): void {
    $relative = safePathRelative();
    $stockBody = '<?php // OLD stock PermissionEnum shipped in a previous release';

    writeSafePathTarget($stockBody);
    // Registry hash equals what is on disk → the consumer never touched it.
    seedSafePathHashRegistry([$relative => md5($stockBody)]);

    $command = bootSafePathUpdateCommand();
    runSafePathUpdate($command);

    expect(readSafePathProperty($command, 'updated'))->toContain($relative)
        ->and(readSafePathProperty($command, 'safePathConflicts'))->toBeEmpty()
        ->and((new Filesystem)->get(base_path($relative)))->toBe(safePathStubContents());
});

it('preserves a consumer-added enum case instead of overwriting it', function (): void {
    $relative = safePathRelative();
    $userBody = "<?php\n\nnamespace App\\Enums;\n\nenum PermissionEnum: string\n{\n    case Read = 'read';\n    case Approve = 'approve';\n}\n";

    writeSafePathTarget($userBody);
    // Registry points at what was originally installed; the on-disk mismatch is
    // exactly what marks the file consumer-modified.
    seedSafePathHashRegistry([$relative => md5('<?php // what the kit originally shipped')]);

    $command = bootSafePathUpdateCommand();
    runSafePathUpdate($command);

    expect((new Filesystem)->get(base_path($relative)))->toBe($userBody)
        ->and(readSafePathProperty($command, 'updated'))->not->toContain($relative)
        ->and(readSafePathProperty($command, 'skipped'))->toContain($relative)
        ->and(readSafePathProperty($command, 'safePathConflicts'))->toContain($relative);
});

it('routes an untracked copy to the prompt rather than guessing', function (): void {
    $relative = safePathRelative();
    $unknownBody = '<?php // no registry record exists for this copy';

    writeSafePathTarget($unknownBody);
    seedSafePathHashRegistry([]);

    $command = bootSafePathUpdateCommand();
    runSafePathUpdate($command);

    expect(readSafePathProperty($command, 'untracked'))->toContain($relative)
        ->and(readSafePathProperty($command, 'updated'))->not->toContain($relative)
        ->and((new Filesystem)->get(base_path($relative)))->toBe($unknownBody);
});

it('still overwrites a modified copy under --force', function (): void {
    $relative = safePathRelative();

    writeSafePathTarget('<?php // consumer edit that --force is expected to discard');
    seedSafePathHashRegistry([$relative => md5('<?php // what the kit originally shipped')]);

    $command = bootSafePathUpdateCommand();
    runSafePathUpdate($command, force: true);

    expect((new Filesystem)->get(base_path($relative)))->toBe(safePathStubContents())
        ->and(readSafePathProperty($command, 'updated'))->toContain($relative)
        ->and(readSafePathProperty($command, 'safePathConflicts'))->toBeEmpty();
});

it('honours an install-time opt-out sentinel', function (): void {
    $relative = safePathRelative();
    $optedOutBody = '<?php // consumer opted this path out at install time';

    writeSafePathTarget($optedOutBody);
    seedSafePathHashRegistry([$relative => '__skipped__']);

    $command = bootSafePathUpdateCommand();
    runSafePathUpdate($command);

    expect((new Filesystem)->get(base_path($relative)))->toBe($optedOutBody)
        ->and(readSafePathProperty($command, 'updated'))->not->toContain($relative)
        ->and(readSafePathProperty($command, 'untracked'))->not->toContain($relative);
});
