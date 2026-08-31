<?php

use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory as ComponentsFactory;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Lvntr\StarterKit\Console\Commands\InstallCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * InstallCommand::step() decides what a failed install step costs: a mandatory
 * step aborts the run (so the command exits non-zero and the checkpoint keeps it
 * pending), a best-effort step only warns. Before this, every step's return
 * value was discarded — a failed `migrate` printed DONE and the install exited 0.
 *
 * The runner is exercised directly: it is the whole decision, and driving a full
 * sk:install through Testbench would test the publish machinery instead.
 */

/**
 * Build an InstallCommand wired with just enough console plumbing to run step().
 *
 * dryRun is forced on so markStepComplete()'s checkpoint write is a no-op — the
 * in-memory completedSteps list is what the assertions read.
 *
 * @return array{0: InstallCommand, 1: BufferedOutput}
 */
function stepCommand(array $options = []): array
{
    $command = new InstallCommand;

    $output = new BufferedOutput;
    $style = new OutputStyle(new ArrayInput($options, $command->getDefinition()), $output);
    $command->setInput(new ArrayInput($options, $command->getDefinition()));
    $command->setOutput($style);

    foreach ([
        'files' => new Filesystem,
        'components' => new ComponentsFactory($style),
        'dryRun' => true,
    ] as $property => $value) {
        $ref = new ReflectionProperty($command, $property);
        $ref->setAccessible(true);
        $ref->setValue($command, $value);
    }

    return [$command, $output];
}

/**
 * Read a private property off the command under test.
 */
function stepProperty(InstallCommand $command, string $property): mixed
{
    $ref = new ReflectionProperty($command, $property);
    $ref->setAccessible(true);

    return $ref->getValue($command);
}

/**
 * Invoke the private step() runner.
 */
function runStep(InstallCommand $command, string $label, callable $callback, bool $mandatory = true): bool
{
    $method = new ReflectionMethod($command, 'step');
    $method->setAccessible(true);

    return (bool) $method->invoke($command, $label, $callback, $mandatory);
}

// ── Success paths ─────────────────────────────────────────────────────────

it('marks a step complete when the callback reports success', function (): void {
    [$command, $output] = stepCommand();

    expect(runStep($command, 'Running migrations', fn () => true))->toBeTrue();
    expect(stepProperty($command, 'completedSteps'))->toBe(['Running migrations']);
    expect($output->fetch())->toContain('DONE');
});

it('treats a callback that returns nothing as a success', function (): void {
    // Every legacy step is a void closure that only throws on error — those must
    // keep passing, which is why only an explicit `false` counts as a failure.
    [$command] = stepCommand();

    expect(runStep($command, 'Merging package.json', function (): void {}))->toBeTrue();
    expect(stepProperty($command, 'completedSteps'))->toBe(['Merging package.json']);
});

// ── Mandatory failure ─────────────────────────────────────────────────────

it('aborts the run when a mandatory step fails', function (): void {
    [$command] = stepCommand();

    $detailProperty = new ReflectionProperty($command, 'stepFailureDetail');
    $detailProperty->setAccessible(true);

    $run = fn () => runStep($command, 'Running migrations', function () use ($detailProperty, $command) {
        $detailProperty->setValue($command, '`php artisan migrate` exited with code 1.');

        return false;
    });

    expect($run)->toThrow(RuntimeException::class, '`php artisan migrate` exited with code 1.');

    // The checkpoint must NOT record it: the run has to stay resumable.
    expect(stepProperty($command, 'completedSteps'))->toBe([]);
});

it('keeps the failed step as the current step so the failure names it', function (): void {
    [$command] = stepCommand();

    try {
        runStep($command, 'Seeding permissions', fn () => false);
    } catch (RuntimeException) {
        // Expected — renderStepFailure() reads currentStep to name the step.
    }

    expect(stepProperty($command, 'currentStep'))->toBe('Seeding permissions');
});

// ── Best-effort failure ───────────────────────────────────────────────────

it('lets a best-effort step fail without aborting the install', function (): void {
    [$command, $output] = stepCommand();

    $failed = runStep($command, 'Building frontend assets', fn () => false, mandatory: false);

    expect($failed)->toBeFalse();
    expect(stepProperty($command, 'bestEffortFailures'))->toBe(['Building frontend assets']);
    // Not completed → a resume retries it; not fatal → the install goes on.
    expect(stepProperty($command, 'completedSteps'))->toBe([]);
    expect(stepProperty($command, 'currentStep'))->toBeNull();
    expect($output->fetch())->toContain('FAILED (non-fatal)');
});

// ── Failure detail rendering ──────────────────────────────────────────────

it('escapes and trims a sub-command output tail', function (): void {
    [$command] = stepCommand();

    $method = new ReflectionMethod($command, 'outputTail');
    $method->setAccessible(true);

    $tail = $method->invoke($command, "line1\nline2\nline3\nline4", 2);

    expect($tail)->toContain('line3')
        ->and($tail)->toContain('line4')
        ->and($tail)->not->toContain('line1');

    // A console tag inside a sub-command's output must not be interpreted (or
    // blow up the formatter) when the installer prints the tail.
    expect($method->invoke($command, 'SQLSTATE<HY000> [1045]'))->toContain('\\<HY000\\>');

    expect($method->invoke($command, "  \n "))->toBe('');
});

// ── Artisan runner ────────────────────────────────────────────────────────

it('reports a failing artisan sub-command with its exit code and output tail', function (): void {
    Artisan::command('sk-test:failing-step', function () {
        $this->line('SQLSTATE[HY000] [2002] Connection refused');

        return 1;
    });

    Artisan::command('sk-test:passing-step', fn () => 0);

    [$command] = stepCommand();
    $command->setLaravel($this->app);

    // getArtisan() is protected on the console kernel; the console application
    // it builds is what runCommand() resolves sub-commands through.
    $kernel = $this->app[Kernel::class];
    $getArtisan = new ReflectionMethod($kernel, 'getArtisan');
    $getArtisan->setAccessible(true);
    $command->setApplication($getArtisan->invoke($kernel));

    $method = new ReflectionMethod($command, 'runArtisan');
    $method->setAccessible(true);

    expect($method->invoke($command, 'sk-test:passing-step'))->toBeTrue();
    expect(stepProperty($command, 'stepFailureDetail'))->toBeNull();

    // The whole point of the change: a non-zero exit code becomes a false, and
    // the sub-command's own output is what explains it.
    expect($method->invoke($command, 'sk-test:failing-step'))->toBeFalse();
    expect(stepProperty($command, 'stepFailureDetail'))
        ->toContain('`php artisan sk-test:failing-step` exited with code 1.')
        ->toContain('Connection refused');
});
