<?php

namespace Lvntr\StarterKit\Console\Commands\Concerns;

/**
 * The ONE three-way comparison that decides whether a shipped stub may be
 * written over a file the consumer already has.
 *
 * `sk:update` grew this rule first (see UpdateCommand::updateSafePaths()) after
 * a plain "the files differ, so copy" loop silently destroyed consumer edits.
 * `sk:install` had the same loop and the same bug: a re-install copied every
 * non-preservable path over whatever was there, so an edited controller was
 * gone with nothing in the summary saying so. Both commands now ask this trait
 * instead, because two copies of a data-loss guard is one copy too many — the
 * second one drifts.
 *
 * The inputs are hashes, not paths: the decision is pure, so both commands can
 * be tested against it directly and neither can accidentally key on a different
 * form of the same path than the one it recorded.
 *
 *   $stubHash     md5 of the file the package ships NOW
 *   $targetHash   md5 of the file on disk, or null when it is absent
 *   $recordedHash the registry entry for this path — the hash of the stub AS
 *                 SHIPPED at the last install/update, or a sentinel, or null
 *                 when the path was never tracked
 *
 * The registry stores the STUB hash (v2 format), which is what makes the
 * three-way possible at all: `stub === recorded` means the package has shipped
 * nothing new since the file landed, so whatever the target now contains is the
 * consumer's; `target === recorded` means the consumer never touched what we
 * gave them, so refreshing is provably lossless.
 */
trait ComparesPublishedStubs
{
    /** On-disk copy already equals the shipped stub — writing is a no-op. */
    public const STUB_IDENTICAL = 'identical';

    /** Safe to write: no target, an explicit --force, or a provably untouched copy. */
    public const STUB_WRITE = 'write';

    /** Registry sentinel from an install-time opt-out (e.g. --without-ai-skill). */
    public const STUB_OPTED_OUT = 'opted-out';

    /** No usable registry record — a stale stock file and an edited one look alike. */
    public const STUB_UNTRACKED = 'untracked';

    /**
     * The package has shipped nothing new since this file landed, yet the copy
     * on disk differs: the difference is the consumer's own edit. Preserve it —
     * and note that there is no "new version" to offer them.
     */
    public const STUB_UP_TO_DATE = 'up-to-date';

    /** A new version exists AND the consumer edited their copy. Preserve + report. */
    public const STUB_MODIFIED = 'modified';

    /**
     * Decide what to do with one shipped stub.
     *
     * Order matters and is the rule itself:
     *
     *   1. target already equals the stub  → nothing to do (checked before
     *      --force so a forced run does not report untouched files as written)
     *   2. no target                       → first copy of this file
     *   3. --force                         → the documented "take the package
     *      version and discard my edits" escape hatch
     *   4. '__skipped__'                   → an opt-out the consumer chose; the
     *      file on disk is their business
     *   5. no record / '__deleted__'       → untracked: the caller decides
     *      (sk:update asks, sk:install writes), because guessing silently is
     *      what caused the loss this rule exists to prevent
     *   6. recorded === stub               → nothing new shipped → their edit
     *   7. recorded === target             → untouched since we shipped it → write
     *   8. otherwise                       → new version + local edit → preserve
     */
    protected function decidePublishedStub(
        string $stubHash,
        ?string $targetHash,
        ?string $recordedHash,
        bool $force,
    ): string {
        if ($targetHash === $stubHash) {
            return self::STUB_IDENTICAL;
        }

        if ($targetHash === null) {
            return self::STUB_WRITE;
        }

        if ($force) {
            return self::STUB_WRITE;
        }

        if ($recordedHash === '__skipped__') {
            return self::STUB_OPTED_OUT;
        }

        if ($recordedHash === null || $recordedHash === '__deleted__') {
            return self::STUB_UNTRACKED;
        }

        if ($recordedHash === $stubHash) {
            return self::STUB_UP_TO_DATE;
        }

        if ($recordedHash === $targetHash) {
            return self::STUB_WRITE;
        }

        return self::STUB_MODIFIED;
    }
}
