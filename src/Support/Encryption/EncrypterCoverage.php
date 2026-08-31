<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Support\Encryption;

use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Laravel\Fortify\Fortify;
use Lvntr\StarterKit\Console\Commands\EncryptionHealthCommand;
use Lvntr\StarterKit\Console\Commands\EncryptionRekeyCommand;
use Lvntr\StarterKit\StarterKitServiceProvider;
use Throwable;

/**
 * Which encrypter actually serves each encrypted surface, and is the kit's key
 * chain the one behind it?
 *
 * ## The gap this closes
 *
 * `encryption:health` and `encryption:rekey` both reason from
 * {@see DataEncrypterFactory}'s chain: they open the ciphertext with each key
 * in turn and report which one fit. That is a statement about the STORED bytes
 * and says nothing about the object the application will use to read and write
 * those bytes tomorrow. The two can diverge, and every divergence is silent:
 *
 *   - Fortify's 2FA columns are served by `Fortify::currentEncrypter()`, which
 *     is `Fortify::$encrypter ?? Model::$encrypter ?? Crypt`. The kit installs
 *     its own shim only when BOTH statics are still null
 *     ({@see StarterKitServiceProvider::configureDataEncryption()}),
 *     because overwriting a consumer's encrypter would lock every 2FA user out.
 *     A consumer that set either one therefore keeps a foreign encrypter — and
 *     a rekey onto DATA_ENCRYPTION_KEY would make its 2FA columns unreadable at
 *     the login challenge.
 *   - With neither static set and no Fortify shim reachable, the fallback is
 *     the `Crypt` facade, which is APP_KEY-only. On an install that HAS adopted
 *     a dedicated key that is a foreign encrypter too.
 *   - `settings.value` is served by {@see DataCrypt}, i.e. the
 *     `sk.data_encrypter` container binding. A consumer that rebound it wins,
 *     for the same reason and with the same consequence.
 *
 * ## Two independent questions, deliberately kept apart
 *
 *   1. WHO built it — {@see KitOwnedEncrypter}. Unrecoverable from behaviour;
 *      an app-built encrypter on the same key answers every method identically.
 *   2. WHAT key it writes with — byte comparison against the kit's primary.
 *      This is the one that decides whether data is at risk.
 *
 * A surface can be app-built and still perfectly covered (question 1 no,
 * question 2 yes); it is reported as such rather than being flattened into a
 * warning, because a warning an operator cannot act on is a warning they learn
 * to skip.
 *
 * ## Report only
 *
 * Nothing here rebinds, overrides or repairs anything, and no key material is
 * returned, printed or logged — only booleans derived from `hash_equals` and
 * class-name labels. A surface it cannot inspect is reported as
 * {@see self::STATUS_UNKNOWN}; guessing "covered" is the one answer that
 * destroys data.
 *
 * @see EncryptionRekeyCommand
 * @see EncryptionHealthCommand
 */
final class EncrypterCoverage
{
    /**
     * The serving encrypter writes with the kit's primary key AND reads the
     * kit's whole chain. Everything health reports about this surface holds.
     */
    public const STATUS_COVERED = 'covered';

    /**
     * Writes land on the kit's primary key, but the read chain differs — a
     * value still on a previous key may already be unreadable there. A rekey
     * FIXES this surface (every value moves onto the primary), so it does not
     * block one.
     */
    public const STATUS_PARTIAL = 'partial-chain';

    /**
     * The serving encrypter writes with a DIFFERENT key. Health's attribution
     * does not describe this surface, and a rekey onto the kit's primary would
     * make it unreadable to the code that serves it.
     */
    public const STATUS_FOREIGN = 'foreign-key';

    /**
     * The serving encrypter could not be resolved or inspected. Reported as-is:
     * an honest "unknown" is worth more than a confident wrong answer.
     */
    public const STATUS_UNKNOWN = 'unknown';

    /**
     * Nothing writes this surface on this install (the package that owns it is
     * not installed). Stored rows are still scannable and still rekeyable.
     */
    public const STATUS_NO_WRITER = 'no-writer';

    /**
     * Statuses under which the kit may NOT claim a surface is handled.
     *
     * @var list<string>
     */
    private const NOT_VOUCHED = [self::STATUS_FOREIGN, self::STATUS_UNKNOWN];

    /**
     * Coverage for every surface the encryption commands know about.
     *
     * @param  list<array{source: string, key: string}>  $chain  the kit's resolved key chain, primary first
     * @return list<array{surface: string, status: string, encrypter: string, kit_built: bool, detail: string}>
     */
    public function report(array $chain): array
    {
        return [
            $this->settingsSurface($chain),
            $this->twoFactorSurface($chain),
        ];
    }

    /**
     * Whether a surface's status means the kit cannot vouch for it.
     */
    public static function isNotVouched(string $status): bool
    {
        return in_array($status, self::NOT_VOUCHED, true);
    }

    /**
     * Is the `starter-kit.encryption` config block present at all?
     *
     * FALSE is the pre-existing trap this method exists to expose. The block is
     * merged in from the package default by `mergeConfigFrom()`, so a published
     * `config/starter-kit.php` that predates the encryption release normally
     * still resolves it — EXCEPT when the app ran `config:cache`, which turns
     * `mergeConfigFrom()` into a no-op. Then `starter-kit.encryption` is
     * whatever the stale published file said, i.e. nothing, and every key below
     * it reads null. DATA_ENCRYPTION_KEY is inert: the primary key silently
     * falls back to APP_KEY, and the operator who set that variable believes
     * their data is decoupled from `key:generate` when it is not.
     *
     * Nothing else in the kit can see this. The factory reads through
     * `config()` and gets a legitimate-looking null; health then scans rows
     * that all read with APP_KEY and reports "safe to clear".
     */
    public function configBlockPresent(): bool
    {
        return is_array(config('starter-kit.encryption'));
    }

    /**
     * Whether the app's configuration is cached, which is what makes a stale
     * published config file visible in the first place.
     *
     * Reported alongside {@see self::configBlockPresent()} so the fix named to
     * the operator is the one that applies to their install.
     */
    public function configurationIsCached(): bool
    {
        try {
            return (bool) app()->configurationIsCached();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether `DATA_ENCRYPTION_KEY` carries a value in the PROCESS environment,
     * regardless of whether the config block exposes it.
     *
     * Only ever a boolean leaves this method. Deliberately reads `env()` rather
     * than `config()`: the whole point is to catch the case where a value IS
     * set and the config block is not passing it through. It can answer false
     * on an install whose key lives in a `.env` file that a cached config
     * skipped loading, which is why it only ever SHARPENS the wording of the
     * missing-block warning and never triggers one on its own.
     */
    public function primaryKeyPresentInEnvironment(): bool
    {
        $value = env(DataEncrypterFactory::PRIMARY_ENV_KEY);

        return is_string($value) && trim($value) !== '';
    }

    /**
     * `settings.value` — served by {@see DataCrypt}, i.e. the container binding.
     *
     * @param  list<array{source: string, key: string}>  $chain
     * @return array{surface: string, status: string, encrypter: string, kit_built: bool, detail: string}
     */
    private function settingsSurface(array $chain): array
    {
        try {
            $encrypter = app(DataEncrypterFactory::BINDING);
        } catch (Throwable $e) {
            return $this->entry(
                EncryptionRekeyCommand::SURFACE_SETTINGS,
                self::STATUS_UNKNOWN,
                'unresolved',
                false,
                sprintf(
                    'The [%s] binding could not be resolved (%s), so it is not known which encrypter reads and '
                    .'writes settings values.',
                    DataEncrypterFactory::BINDING,
                    $e->getMessage(),
                ),
            );
        }

        if (! $encrypter instanceof EncrypterContract) {
            return $this->entry(
                EncryptionRekeyCommand::SURFACE_SETTINGS,
                self::STATUS_UNKNOWN,
                $this->label($encrypter),
                false,
                sprintf(
                    'The [%s] binding resolved to something that is not an Encrypter, so it cannot be inspected.',
                    DataEncrypterFactory::BINDING,
                ),
            );
        }

        return $this->classify(
            EncryptionRekeyCommand::SURFACE_SETTINGS,
            $encrypter,
            $chain,
            sprintf('DataCrypt / the [%s] container binding', DataEncrypterFactory::BINDING),
        );
    }

    /**
     * `two_factor_secret` + `two_factor_recovery_codes` — served by
     * `Fortify::currentEncrypter()`.
     *
     * The resolution order is reimplemented here rather than calling
     * `currentEncrypter()`, for the same reason the service provider does not
     * call it: its `Crypt` fallback resolves the app encrypter, which THROWS
     * when APP_KEY is absent. A diagnostic command must not be the thing that
     * blows up on a half-configured install.
     *
     * @param  list<array{source: string, key: string}>  $chain
     * @return array{surface: string, status: string, encrypter: string, kit_built: bool, detail: string}
     */
    private function twoFactorSurface(array $chain): array
    {
        if (! class_exists(Fortify::class)) {
            return $this->entry(
                EncryptionRekeyCommand::SURFACE_TWO_FACTOR,
                self::STATUS_NO_WRITER,
                'none',
                false,
                'Laravel Fortify is not installed, so nothing on this install reads or writes the two-factor '
                .'columns. Any rows already stored are still scanned and can still be rekeyed.',
            );
        }

        $encrypter = Fortify::$encrypter ?? Model::$encrypter ?? null;
        $origin = Fortify::$encrypter !== null
            ? 'Fortify::encryptUsing()'
            : ($encrypter !== null ? 'Model::encryptUsing()' : 'the Crypt facade (Fortify\'s last fallback)');

        if ($encrypter === null) {
            try {
                $encrypter = Crypt::getFacadeRoot();
            } catch (Throwable $e) {
                return $this->entry(
                    EncryptionRekeyCommand::SURFACE_TWO_FACTOR,
                    self::STATUS_UNKNOWN,
                    'unresolved',
                    false,
                    sprintf(
                        'Neither Fortify::$encrypter nor Model::$encrypter is set, so Fortify falls back to the '
                        .'Crypt facade — which does not resolve here (%s).',
                        $e->getMessage(),
                    ),
                );
            }
        }

        if (! $encrypter instanceof EncrypterContract) {
            return $this->entry(
                EncryptionRekeyCommand::SURFACE_TWO_FACTOR,
                self::STATUS_UNKNOWN,
                $this->label($encrypter),
                false,
                'Fortify\'s encrypter is not an Illuminate Encrypter, so its key cannot be inspected.',
            );
        }

        return $this->classify(EncryptionRekeyCommand::SURFACE_TWO_FACTOR, $encrypter, $chain, $origin);
    }

    /**
     * Compare a serving encrypter against the kit's chain and name the result.
     *
     * @param  list<array{source: string, key: string}>  $chain
     * @return array{surface: string, status: string, encrypter: string, kit_built: bool, detail: string}
     */
    private function classify(string $surface, EncrypterContract $encrypter, array $chain, string $origin): array
    {
        $kitBuilt = $encrypter instanceof KitOwnedEncrypter;

        try {
            $writeKey = $encrypter->getKey();
            $readKeys = $encrypter->getAllKeys();
        } catch (Throwable $e) {
            return $this->entry($surface, self::STATUS_UNKNOWN, $this->label($encrypter), $kitBuilt, sprintf(
                'The encrypter behind %s could not be inspected (%s), so the kit cannot say which key serves this '
                .'surface.',
                $origin,
                $e->getMessage(),
            ));
        }

        if (! is_string($writeKey) || $writeKey === '' || $chain === []) {
            return $this->entry($surface, self::STATUS_UNKNOWN, $this->label($encrypter), $kitBuilt, sprintf(
                'The encrypter behind %s reported no usable key, so this surface cannot be attributed.',
                $origin,
            ));
        }

        if (! hash_equals($chain[0]['key'], $writeKey)) {
            return $this->entry($surface, self::STATUS_FOREIGN, $this->label($encrypter), $kitBuilt, sprintf(
                'This surface is served by %s, whose WRITE key is not the kit\'s primary (%s). Values written '
                .'through it are not on the key this report attributes rows to, and re-encrypting them onto the '
                .'kit\'s primary would make them unreadable to the code that serves them.',
                $origin,
                $chain[0]['source'],
            ));
        }

        if (! $this->chainsMatch($chain, is_array($readKeys) ? $readKeys : [])) {
            return $this->entry($surface, self::STATUS_PARTIAL, $this->label($encrypter), $kitBuilt, sprintf(
                'This surface is served by %s. It WRITES with the kit\'s primary key (%s), but its read chain is '
                .'not the kit\'s: a value still on a previous key may already be unreadable there. '
                .'`php artisan encryption:rekey` moves every value onto the primary key and closes the gap.',
                $origin,
                $chain[0]['source'],
            ));
        }

        return $this->entry($surface, self::STATUS_COVERED, $this->label($encrypter), $kitBuilt, sprintf(
            'Served by %s on the kit\'s key chain%s.',
            $origin,
            $kitBuilt ? '' : ' (built by the application, not by the kit, but on the same keys)',
        ));
    }

    /**
     * Do two key lists hold the same material in the same order?
     *
     * Order matters: the first entry is the write key and the rest are tried in
     * sequence. Compared with `hash_equals` per entry so no comparison is
     * short-circuited on key bytes.
     *
     * @param  list<array{source: string, key: string}>  $chain
     * @param  array<int, mixed>  $readKeys
     */
    private function chainsMatch(array $chain, array $readKeys): bool
    {
        $readKeys = array_values($readKeys);

        if (count($readKeys) !== count($chain)) {
            return false;
        }

        foreach ($chain as $index => $entry) {
            $candidate = $readKeys[$index];

            if (! is_string($candidate) || ! hash_equals($entry['key'], $candidate)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A printable, key-free name for an encrypter instance.
     *
     * Anonymous classes carry the defining file and line in their name, which is
     * noise in a report; they are collapsed to `<Parent>@anonymous`.
     */
    private function label(mixed $encrypter): string
    {
        if (! is_object($encrypter)) {
            return get_debug_type($encrypter);
        }

        $class = $encrypter::class;

        $marker = strpos($class, '@anonymous');

        return $marker === false ? $class : substr($class, 0, $marker).'@anonymous';
    }

    /**
     * @return array{surface: string, status: string, encrypter: string, kit_built: bool, detail: string}
     */
    private function entry(string $surface, string $status, string $encrypter, bool $kitBuilt, string $detail): array
    {
        return [
            'surface' => $surface,
            'status' => $status,
            'encrypter' => $encrypter,
            'kit_built' => $kitBuilt,
            'detail' => $detail,
        ];
    }
}
