<?php

namespace Lvntr\StarterKit\Console\Commands\Concerns;

use RuntimeException;
use Throwable;

/**
 * Atomic file writes for install/update/eject commands.
 *
 * A plain Filesystem::put() truncates the target the instant it opens the
 * handle, so an interruption mid-write (Ctrl-C, crash, disk-full, killed
 * process) leaves a half-written or empty file. For the hash registry
 * (hashes.json) that means a corrupted registry — the most expensive failure,
 * because the next sk:update can no longer tell consumer-owned files from
 * removable ones.
 *
 * atomicPut() writes into a sibling temp file and then rename()s it over the
 * target. rename() is atomic on POSIX filesystems, so a reader/consumer either
 * sees the complete old file or the complete new file — never a truncated one.
 * The temp file is created in the SAME directory as the target on purpose: a
 * cross-filesystem rename degrades into copy+unlink and loses atomicity, so
 * keeping temp and target on one filesystem preserves the guarantee.
 *
 * Requires the using class to expose an Illuminate\Filesystem\Filesystem
 * instance as $this->files.
 */
trait WritesFilesAtomically
{
    /**
     * Write $contents to $path atomically (temp file + rename).
     */
    protected function atomicPut(string $path, string $contents): void
    {
        $dir = dirname($path);

        if (! $this->files->isDirectory($dir)) {
            $this->files->makeDirectory($dir, 0755, true);
        }

        // A directory we could not create is not a reason to skip the write
        // silently — the caller believes the file is on disk after this returns.
        if (! $this->files->isDirectory($dir)) {
            throw new RuntimeException("Atomic write failed: could not create the directory [{$dir}].");
        }

        // Temp file lives next to the target so the final rename stays within a
        // single filesystem (see class docblock). The leading dot + random
        // suffix keep concurrent writes and directory scanners from colliding.
        $temp = $dir.DIRECTORY_SEPARATOR.'.'.basename($path).'.tmp'.bin2hex(random_bytes(6));

        try {
            // put() returns false (or a short byte count on a full disk) instead
            // of throwing, so an unchecked call renames a truncated temp file
            // over a good target — the exact corruption this trait prevents.
            $written = $this->files->put($temp, $contents);

            if ($written === false || $written !== strlen($contents)) {
                throw new RuntimeException("Atomic write failed: could not write the temp file for [{$path}].");
            }

            $this->flushToDisk($temp);

            if (! @rename($temp, $path)) {
                throw new RuntimeException("Atomic write failed: could not move temp file into place for [{$path}].");
            }
        } catch (Throwable $e) {
            if ($this->files->exists($temp)) {
                $this->files->delete($temp);
            }

            throw $e;
        }
    }

    /**
     * Push the temp file's bytes out of the kernel page cache before it is
     * renamed into place.
     *
     * rename() makes the SWAP atomic, not the CONTENT durable: on a crash or
     * power loss the metadata change can reach the disk while the data behind
     * it has not, leaving a correctly named registry full of zeroes. fsync
     * closes that window. The write itself still goes through $this->files->put
     * so an injected Filesystem stays able to observe it (the encryption
     * commands' write-order tests depend on that), which is why the flush opens
     * its own handle instead of writing through one.
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
}
