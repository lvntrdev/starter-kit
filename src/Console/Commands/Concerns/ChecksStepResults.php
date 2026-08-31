<?php

namespace Lvntr\StarterKit\Console\Commands\Concerns;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

/**
 * Exit-code aware runners for the install/update/upgrade commands.
 *
 * These commands used to invoke `callSilently()` and `Process::run()` and drop
 * the result on the floor: a `migrate` that died on a bad connection printed
 * "DONE", the checkpoint recorded the step as finished, and the command exited
 * 0 — so a consumer's CI went green over a half-installed application.
 *
 * Both runners return a plain bool and leave the reason in
 * $stepFailureDetail for the caller's own step reporter to render.
 */
trait ChecksStepResults
{
    /**
     * Why the last checked runner reported a failure — the sub-command's exit
     * code plus the tail of its output. Null when nothing has failed since the
     * caller last reset it.
     */
    protected ?string $stepFailureDetail = null;

    /**
     * Run an Artisan sub-command and report whether it succeeded.
     *
     * Output is captured rather than suppressed (which is all callSilently()
     * does differently) so a failure can show why, while a success stays just as
     * quiet as callSilently() was.
     *
     * `$echo` is for a step that was VISIBLE before this trait existed: a
     * sub-command whose successful output is the point of running it — sk:update
     * prints the conflict list the operator has to act on. Buffering that would
     * turn a checked exit code into a lost report, so those call sites write
     * straight to the console and rely on the already-printed output instead of
     * the failure tail.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function runArtisan(string $command, array $arguments = [], bool $echo = false): bool
    {
        $buffer = new BufferedOutput;

        $exitCode = $this->runCommand($command, $arguments, $echo ? $this->output : $buffer);

        if ($exitCode === self::SUCCESS) {
            return true;
        }

        $this->stepFailureDetail = sprintf(
            '`php artisan %s` exited with code %d.%s',
            $command,
            $exitCode,
            $echo ? '' : $this->outputTail($buffer->fetch()),
        );

        return false;
    }

    /**
     * Run an external process and report whether it succeeded, surfacing the
     * tail of its stderr (falling back to stdout) when it did not.
     *
     * @param  list<string>  $command
     */
    protected function runProcessStep(array $command, int $timeout = 60, bool $tty = false): bool
    {
        $process = new Process($command, base_path(), null, null, $timeout);

        if ($tty && Process::isTtySupported()) {
            $process->setTty(true);
        }

        try {
            $process->run();
        } catch (\Throwable $e) {
            // A process can fail WITHOUT ever producing an exit code: the
            // timeout above raises ProcessTimedOutException, a missing binary or
            // an unusable cwd raises ProcessStartFailedException/RuntimeException.
            // Left to escape, those fly straight past the caller's `mandatory:
            // false` contract into its outer catch — so a slow `npm install`
            // aborted an otherwise complete install, which is exactly what the
            // best-effort classification exists to prevent.
            //
            // The message is escaped for the same reason outputTail() escapes:
            // it embeds the commandline, and a console tag inside it would
            // otherwise be interpreted by (or break) the caller's formatter.
            $this->stepFailureDetail = sprintf(
                '`%s` did not complete: %s%s',
                implode(' ', $command),
                OutputFormatter::escape($e->getMessage()),
                $this->outputTail($this->processOutput($process)),
            );

            return false;
        }

        if ($process->isSuccessful()) {
            return true;
        }

        $this->stepFailureDetail = sprintf(
            '`%s` exited with code %s.%s',
            implode(' ', $command),
            $process->getExitCode() ?? 'unknown',
            $this->outputTail($this->processOutput($process)),
        );

        return false;
    }

    /**
     * Whatever a failed process left readable, or '' when there is nothing.
     *
     * Two cases yield nothing and must not be read: a TTY process streams
     * straight to the terminal and buffers no pipes (the operator has already
     * seen it), and a process that never started has no pipes at all — asking
     * either for its output throws a LogicException on top of the failure being
     * reported.
     */
    private function processOutput(Process $process): string
    {
        if ($process->isTty() || ! $process->isStarted()) {
            return '';
        }

        try {
            return $process->getErrorOutput() ?: $process->getOutput();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Last few lines of a sub-command's output, indented and escaped so console
     * formatting tags inside it cannot break (or colour) the caller's output.
     */
    protected function outputTail(string $output, int $lines = 5): string
    {
        $trimmed = trim($output);

        if ($trimmed === '') {
            return '';
        }

        $tail = array_slice(preg_split('/\R/', $trimmed) ?: [], -$lines);

        return PHP_EOL.'  '.OutputFormatter::escape(implode(PHP_EOL.'  ', $tail));
    }
}
