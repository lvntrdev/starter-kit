<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Support\Encryption;

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;
use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Laravel\Fortify\Fortify;
use Lvntr\StarterKit\Console\Commands\EncryptionHealthCommand;
use Lvntr\StarterKit\Console\Commands\EncryptionRekeyCommand;
use Lvntr\StarterKit\StarterKitServiceProvider;
use SensitiveParameter;
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
 * ## A third question, asked only of a cached config
 *
 * {@see self::envChainDiverges()} answers whether the chain `config()` resolved
 * is still the chain the environment imposes. It is meaningless until the app
 * runs `config:cache` and decisive the moment it does — see that method.
 *
 * ## Report only
 *
 * Nothing here rebinds, overrides or repairs anything, and no key material is
 * returned, printed or logged — only booleans derived from `hash_equals` and
 * class-name labels. That holds for the `.env` probe too: it reads key material
 * out of a file and returns a boolean about it, never the material itself, and
 * the parse errors it swallows name env vars only. A surface it cannot inspect
 * is reported as {@see self::STATUS_UNKNOWN}; guessing "covered" is the one
 * answer that destroys data.
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
     * Does the key chain the ENVIRONMENT imposes match the one this run
     * resolved through `config()`?
     *
     * ## Why a cached config makes this the decisive question
     *
     * `config:cache` freezes every `env()` call into a PHP array, and Laravel
     * then skips loading the environment file entirely —
     * `LoadEnvironmentVariables::bootstrap()` returns early when the
     * configuration is cached. From that moment `config()` and `.env` are two
     * independent sources drifting apart in silence: an operator who edits
     * DATA_ENCRYPTION_KEY, or a deploy that ships a cache built on another
     * host, runs one chain while every report describes the other.
     *
     * That is fatal for exactly one output of {@see EncryptionHealthCommand}:
     * `safe-to-clear`, which asserts that every stored value opens with the
     * primary key alone — attributed with the CACHED chain. Clear
     * DATA_ENCRYPTION_PREVIOUS_KEYS on that advice, rebuild the cache, and the
     * app comes back on the environment's chain, which may no longer hold the
     * key those rows were written with. By then the list that held it is gone.
     *
     * ## Three answers, and `null` is not a quiet `false`
     *
     *   - `null`  — the configuration is NOT cached, so `config()` reads the
     *               live files and there is nothing to be stale against. No
     *               file is read and no verdict may move.
     *   - `false` — a COMPLETE chain was rebuilt from the environment and it
     *               matches, key for key, in order. Only this answer lets
     *               `safe-to-clear` survive a cached config.
     *   - `true`  — the chains differ, OR the file exists and could not be read
     *               or parsed, OR no key at all could be resolved. "Could not
     *               check" is folded into "diverges" on purpose: this feeds the
     *               one verdict that destroys data when it is wrong, so the
     *               unknown fails closed.
     *
     * ## Precedence mirrors phpdotenv, not the file alone
     *
     * A variable defined in the real process environment WINS over a line in
     * `.env` — phpdotenv's repository is immutable and never overwrites one. A
     * container that injects DATA_ENCRYPTION_KEY while a stale placeholder sits
     * in the file is healthy, and reading the file alone would manufacture a
     * divergence for it. Under a cached config `env()` sees ONLY the real
     * environment (the file was never loaded), which is precisely the set that
     * wins, so `env() ?? file line` reproduces what the next uncached boot
     * resolves.
     *
     * @param  list<array{source: string, key: string}>  $chain  the chain the command resolved through config()
     */
    public function envChainDiverges(array $chain): ?bool
    {
        if (! $this->configurationIsCached()) {
            return null;
        }

        $content = $this->environmentFileContents();

        if ($content === null) {
            return true;
        }

        try {
            $material = $this->environmentChainMaterial($content);
        } catch (Throwable) {
            // A malformed value aborts the COMPARISON, never the command. The
            // honest report is that this chain could not be rebuilt, and the
            // command's own resolution already succeeded or it would not be
            // here.
            return true;
        }

        // No key anywhere in the environment: after a `config:clear` this app
        // would not resolve a chain at all, so nothing below can be vouched for.
        if ($material === []) {
            return true;
        }

        return ! $this->chainsMatch($chain, $material);
    }

    /**
     * The environment file's body, `''` when there is no such file, and null
     * when one exists that this process cannot read.
     *
     * The distinction carries weight. A MISSING file is a determinate answer:
     * phpdotenv `safeLoad()`s it, so it contributes no lines and the process
     * environment alone decides the chain — a container that injects every key
     * and ships no `.env` is a healthy install and must not be reported as
     * divergent for ever. A file that EXISTS and cannot be read is the
     * opposite: it may carry lines nothing here can see, so it is unknown, and
     * unknown fails closed.
     */
    private function environmentFileContents(): ?string
    {
        $path = $this->environmentFilePath();

        if ($path === null) {
            return null;
        }

        if (! is_file($path)) {
            return '';
        }

        if (! is_readable($path)) {
            return null;
        }

        $content = @file_get_contents($path);

        return $content === false ? null : $content;
    }

    /**
     * The file Laravel would load on the next uncached boot.
     *
     * `environmentFilePath()` rather than `base_path('.env')`: an app may have
     * called `useEnvironmentPath()` or `loadEnvironmentFrom()`, and reading the
     * wrong file would manufacture a divergence out of nothing. The
     * `.env.<APP_ENV>` probe mirrors
     * `LoadEnvironmentVariables::checkForSpecificEnvironmentFile()`, which
     * prefers that file when the environment is named externally — and with a
     * cached config the external environment is the only thing `env('APP_ENV')`
     * can be reading.
     */
    private function environmentFilePath(): ?string
    {
        $app = app();

        if (! method_exists($app, 'environmentFilePath')) {
            return null;
        }

        try {
            $path = $app->environmentFilePath();
        } catch (Throwable) {
            return null;
        }

        if (! is_string($path) || $path === '') {
            return null;
        }

        $environment = env('APP_ENV');

        if (is_string($environment) && $environment !== '' && is_file($path.'.'.$environment)) {
            return $path.'.'.$environment;
        }

        return $path;
    }

    /**
     * Rebuild {@see DataEncrypterFactory}'s chain from the environment instead
     * of from `config()`, as decoded material.
     *
     * The ORDER is copied deliberately — primary, DATA_ENCRYPTION_PREVIOUS_KEYS,
     * APP_PREVIOUS_KEYS, then APP_KEY last, de-duplicated on decoded bytes. A
     * rebuild that ordered or de-duplicated differently would report a
     * divergence on every healthy install, which is the failure mode that
     * teaches an operator to ignore this command.
     *
     * APP_PREVIOUS_KEYS is read even though this feature did not introduce it:
     * the factory keeps `app.previous_keys` in the chain and the shipped
     * skeleton derives that config value from this variable, so omitting it
     * would flag every install part-way through an APP_KEY rotation.
     *
     * @return list<string> decoded key material; empty when no key could be
     *                      resolved at all, which is itself a divergence
     *
     * @throws \RuntimeException when a value is malformed for the resolved cipher
     */
    private function environmentChainMaterial(string $content): array
    {
        $factory = app(DataEncrypterFactory::class);

        $dedicated = $this->environmentValue($content, DataEncrypterFactory::PRIMARY_ENV_KEY);
        $appKey = $this->environmentValue($content, DataEncrypterFactory::APP_ENV_KEY);

        $material = [];

        if ($dedicated !== null) {
            $this->appendMaterial($material, $factory->parseKey($dedicated, DataEncrypterFactory::PRIMARY_ENV_KEY));
        } elseif ($appKey !== null) {
            $this->appendMaterial($material, $factory->parseKey($appKey, DataEncrypterFactory::APP_ENV_KEY));
        } else {
            return [];
        }

        foreach ($this->environmentValueList($content, DataEncrypterFactory::PREVIOUS_ENV_KEY) as $index => $raw) {
            $this->appendMaterial(
                $material,
                $factory->parseKey($raw, DataEncrypterFactory::PREVIOUS_ENV_KEY.'['.$index.']'),
            );
        }

        foreach ($this->environmentValueList($content, DataEncrypterFactory::APP_PREVIOUS_ENV_KEY) as $index => $raw) {
            $this->appendMaterial(
                $material,
                $factory->parseKey($raw, DataEncrypterFactory::APP_PREVIOUS_ENV_KEY.'['.$index.']'),
            );
        }

        if ($appKey !== null) {
            $this->appendMaterial($material, $factory->parseKey($appKey, DataEncrypterFactory::APP_ENV_KEY));
        }

        return $material;
    }

    /**
     * One variable's effective value: the real process environment first, the
     * environment file only as the fallback.
     *
     * The DEFINEDNESS test is what decides, not the emptiness one. phpdotenv's
     * ImmutableWriter skips any name its repository already `has()`, and an
     * externally defined EMPTY variable is one of those — so `FOO=` exported
     * into the process wins over a populated `FOO=` line in the file, and
     * resolves to no key exactly as DataEncrypterFactory::configString() does
     * with a blank config value. Falling through to the file line there would
     * rebuild a chain the app will never use and report a divergence that does
     * not exist.
     *
     * @return string|null the value VERBATIM (only the emptiness test trims,
     *                     matching DataEncrypterFactory::configString()), or
     *                     null when it is absent or blank
     */
    private function environmentValue(string $content, string $key): ?string
    {
        $external = env($key);

        if ($external !== null) {
            return is_string($external) && trim($external) !== '' ? $external : null;
        }

        return $this->environmentFileValue($content, $key);
    }

    /**
     * A comma-separated variable, normalised exactly as
     * DataEncrypterFactory::normalizeKeyList() normalises the config value it
     * is compared against: trimmed, blanks dropped, duplicates removed, order
     * preserved.
     *
     * @return list<string>
     */
    private function environmentValueList(string $content, string $key): array
    {
        $raw = $this->environmentValue($content, $key);

        if ($raw === null) {
            return [];
        }

        $values = [];

        foreach (explode(',', $raw) as $item) {
            $item = trim($item);

            if ($item === '' || in_array($item, $values, true)) {
                continue;
            }

            $values[] = $item;
        }

        return $values;
    }

    /**
     * Read one variable out of an `.env` body, with phpdotenv's own semantics.
     *
     * This method exists to answer "what would the next uncached boot resolve",
     * so the only defensible parser is the one that boot uses. A hand-rolled
     * regex was wrong in a way that mattered: for a perfectly valid assignment
     * such as `APP_KEY=base64:… # rotated 2026-08`, phpdotenv strips the inline
     * comment while the regex kept it, {@see DataEncrypterFactory::parseKey()}
     * then rejected the value, and a HEALTHY cached install was reported as
     * divergent for ever — a verdict `config:clear` could not clear. Inline
     * comments, escapes, quoting and `export` are all delegated now.
     *
     * `Dotenv::parse()`, not the bare `Parser`: parsing alone stops one step
     * short and hands back `${APP_KEY}` verbatim, because variable
     * interpolation is the LOADER's job. `DATA_ENCRYPTION_KEY=${APP_KEY}` is a
     * valid assignment that boot resolves, so a parse-only read reproduced the
     * exact same false-divergence bug the regex had. `Dotenv::parse()` runs the
     * loader over an isolated in-memory repository — the real environment is
     * neither read nor written — and returns resolved values.
     *
     * The LAST assignment still wins, because phpdotenv's immutable repository
     * only protects variables defined OUTSIDE the file: a later line in the
     * same file does overwrite an earlier one.
     *
     * A body this parser rejects raises, and {@see self::envChainDiverges()}
     * turns that into a fail-closed `true` — an unparseable file is unknown,
     * and unknown is never "safe".
     *
     * NOTE: `EncryptionKeyCommand::readEnvValue()` still carries the regex form
     * this replaced. It is the WRITE path and is deliberately left to its own
     * review round; the two are no longer character-identical.
     *
     * @throws InvalidFileException when the body cannot be parsed
     */
    private function environmentFileValue(string $content, string $key): ?string
    {
        $value = Dotenv::parse($content)[$key] ?? null;

        return $value === null || trim($value) === '' ? null : $value;
    }

    /**
     * Append decoded key material unless identical bytes are already there.
     *
     * Mirrors `DataEncrypterFactory::append()`: the chain this is compared
     * against was de-duplicated that way, and a rebuild that kept duplicates
     * would differ in LENGTH from an otherwise identical chain.
     *
     * @param  list<string>  $material
     */
    private function appendMaterial(array &$material, #[SensitiveParameter] string $key): void
    {
        foreach ($material as $existing) {
            if (hash_equals($existing, $key)) {
                return;
            }
        }

        $material[] = $key;
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
