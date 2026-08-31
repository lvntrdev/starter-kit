<?php

namespace Lvntr\StarterKit\Console\Concerns;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Serialise the commands that rewrite the encryption key chain.
 *
 * `encryption:key` and `encryption:rekey` both read the current chain, decide a
 * new one, and write it back. Two of them running at once — two operators, or a
 * deploy step racing a human — each read the same starting state and each write
 * a different end state, and the second write wins. A key that was still needed
 * to read existing rows can be dropped from the previous-key list that way, and
 * nothing in either command notices.
 *
 * One lock covers BOTH commands, because the dangerous pair is not two rekeys:
 * it is a rotation landing while a rekey is halfway through the rows.
 */
trait HoldsEncryptionRotationLock
{
    private const ROTATION_LOCK_KEY = 'starter-kit:encryption:rotation';

    /**
     * Long enough for a rekey over a large table; the lock is released in a
     * `finally`, so the TTL only matters when the process is killed outright.
     */
    private const ROTATION_LOCK_TTL_SECONDS = 3600;

    /**
     * Run the callback while holding the rotation lock.
     *
     * A cache store that cannot provide locks runs the callback unprotected
     * rather than refusing to rotate at all: that is how these commands have
     * always behaved, and blocking a rotation outright would be the larger
     * regression.
     *
     * The catch is `Throwable`, not `BadMethodCallException`. `Cache::lock()`
     * is not declared on `Repository`; it reaches the store through `__call`,
     * so a store that does not implement it — Laravel's own `session`,
     * `storage` and `apc` stores among them — raises an `Error`, which is not
     * an `Exception` at all. Catching the narrower type let a supported cache
     * driver abort the command before it did any work.
     *
     * @param  callable(): int  $callback
     */
    private function withRotationLock(callable $callback): int
    {
        try {
            $lock = Cache::lock(self::ROTATION_LOCK_KEY, self::ROTATION_LOCK_TTL_SECONDS);
        } catch (Throwable) {
            return $callback();
        }

        if (! $lock->get()) {
            $this->components->error(
                'Another encryption:key or encryption:rekey run is in progress. '
                .'Running both at once can drop a key that is still needed to read existing rows. '
                .'Nothing was read and nothing was written.'
            );

            return Command::FAILURE;
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
