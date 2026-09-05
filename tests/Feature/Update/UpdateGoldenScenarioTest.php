<?php

use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory as ComponentsFactory;
use Illuminate\Filesystem\Filesystem;
use Lvntr\StarterKit\Console\Commands\UpdateCommand;
use Lvntr\StarterKit\StarterKitServiceProvider;
use Lvntr\StarterKit\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

// Feature/Update is not bound to a TestCase in Pest.php (see
// VendorMigratedCleanupTest.php's own note); bind it at file scope here so
// base_path()/config() resolve against the testbench sandbox app.
uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Task 13 — sk:update golden test (stock update / user-edit preserved / registry refresh)
|--------------------------------------------------------------------------
|
| Pins the kit's core "we preserve your edits" promise end to end, against a
| REAL stub file (app/Http/Controllers/Admin/DashboardController.php) run
| through the REAL updateModifiableFiles()/updateHashRegistry() private
| methods — not a re-implementation of the decision rule.
|
| Three scenarios (UpdateCommand.php ~692-732 in the plan's numbering,
| updateModifiableFiles()/updateHashRegistry() in current source):
|   (a) stock, unmodified consumer copy → overwritten with the current stub
|   (b) consumer-modified copy         → left untouched, reported as skipped
|   (c) hash registry                  → refreshed to the CURRENT stub hash
|       for whatever was actually updated in this run (not left pointing at
|       the old stock hash).
|
*/

/**
 * @return array{0: UpdateCommand, 1: BufferedOutput}
 */
function bootUpdateGoldenCommand(): array
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

    return [$command, $buffer];
}

function runUpdateGoldenModifiableFiles(UpdateCommand $command): void
{
    $method = new ReflectionMethod($command, 'updateModifiableFiles');
    $method->invoke($command, false, false);
}

function runUpdateGoldenHashRegistryRefresh(UpdateCommand $command): void
{
    $method = new ReflectionMethod($command, 'updateHashRegistry');
    $method->invoke($command);
}

/** @return list<string> */
function readUpdateGoldenProperty(UpdateCommand $command, string $property): array
{
    $p = new ReflectionProperty($command, $property);

    /** @var list<string> */
    return $p->getValue($command);
}

/** Point the hash registry at a fresh temp file for the current test. */
function seedUpdateGoldenHashRegistry(array $records): string
{
    $fs = new Filesystem;
    $hashFile = sys_get_temp_dir().'/sk_update_golden_hashes_'.uniqid('', true).'.json';
    $fs->put($hashFile, json_encode(['_format' => 'v2'] + $records));

    config(['starter-kit.published_hashes' => $hashFile]);

    return $hashFile;
}

function updateGoldenTargetRelativePath(): string
{
    return 'app/Http/Controllers/Admin/DashboardController.php';
}

function updateGoldenCurrentStubContents(): string
{
    $path = StarterKitServiceProvider::stubsPath(updateGoldenTargetRelativePath());

    return (new Filesystem)->get($path);
}

function cleanupUpdateGoldenFixtures(): void
{
    $fs = new Filesystem;
    $target = base_path(updateGoldenTargetRelativePath());

    if ($fs->exists($target)) {
        $fs->delete($target);
    }
}

afterEach(function (): void {
    cleanupUpdateGoldenFixtures();
});

it('overwrites an unmodified consumer copy with the current stub (stock file updated)', function (): void {
    $relative = updateGoldenTargetRelativePath();
    $stockBody = '<?php // OLD stock DashboardController shipped in a previous release';

    $fs = new Filesystem;
    $fs->ensureDirectoryExists(dirname(base_path($relative)));
    $fs->put(base_path($relative), $stockBody);

    // Registry records the OLD stock hash — proves the consumer never touched
    // the file (their on-disk copy still matches what was originally installed).
    seedUpdateGoldenHashRegistry([$relative => md5($stockBody)]);

    [$command] = bootUpdateGoldenCommand();
    runUpdateGoldenModifiableFiles($command);

    expect(readUpdateGoldenProperty($command, 'updated'))->toContain($relative)
        ->and(readUpdateGoldenProperty($command, 'skipped'))->not->toContain($relative)
        ->and($fs->get(base_path($relative)))->toBe(updateGoldenCurrentStubContents());
});

it('preserves a consumer-modified copy untouched (user edit wins)', function (): void {
    $relative = updateGoldenTargetRelativePath();
    $userBody = '<?php // CONSUMER hand-edited DashboardController with custom widgets';

    $fs = new Filesystem;
    $fs->ensureDirectoryExists(dirname(base_path($relative)));
    $fs->put(base_path($relative), $userBody);

    // Registry hash points at whatever was ORIGINALLY installed, which is
    // neither the user's edited body nor necessarily the current stub — the
    // mismatch against the on-disk file is exactly what marks it "modified".
    seedUpdateGoldenHashRegistry([$relative => md5('<?php // what the kit originally shipped')]);

    [$command] = bootUpdateGoldenCommand();
    runUpdateGoldenModifiableFiles($command);

    expect(readUpdateGoldenProperty($command, 'skipped'))->toContain($relative)
        ->and(readUpdateGoldenProperty($command, 'updated'))->not->toContain($relative)
        ->and($fs->get(base_path($relative)))->toBe($userBody);
});

it('refreshes the hash registry to the CURRENT stub hash for files updated in this run', function (): void {
    $relative = updateGoldenTargetRelativePath();
    $stockBody = '<?php // OLD stock DashboardController shipped in a previous release';

    $fs = new Filesystem;
    $fs->ensureDirectoryExists(dirname(base_path($relative)));
    $fs->put(base_path($relative), $stockBody);

    $hashFile = seedUpdateGoldenHashRegistry([$relative => md5($stockBody)]);

    [$command] = bootUpdateGoldenCommand();
    runUpdateGoldenModifiableFiles($command);
    runUpdateGoldenHashRegistryRefresh($command);

    $registry = json_decode($fs->get($hashFile), true);

    expect($registry[$relative])->toBe(md5(updateGoldenCurrentStubContents()))
        ->and($registry[$relative])->not->toBe(md5($stockBody));
});
