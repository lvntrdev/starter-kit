<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Support\Encryption;

use Illuminate\Encryption\Encrypter;
use RuntimeException;
use SensitiveParameter;

/**
 * Builds the encrypter that protects the kit's data-at-rest secrets.
 *
 * WHY THIS EXISTS
 * ---------------
 * Sensitive `settings.value` rows and the Fortify 2FA secret plus recovery
 * codes used to be encrypted with APP_KEY. A single `php artisan key:generate`
 * on a server migration therefore made all of it permanently unreadable — and
 * silently, because SettingService catches the decrypt failure and returns
 * null. This factory moves that data onto a dedicated DATA_ENCRYPTION_KEY
 * while keeping APP_KEY in the read chain, so adoption never requires a
 * flag-day rewrite.
 *
 * KEY RESOLUTION CONTRACT (the safety property of the whole feature)
 * ------------------------------------------------------------------
 *   DATA_ENCRYPTION_KEY blank -> primary = APP_KEY
 *                                chain   = DATA_ENCRYPTION_PREVIOUS_KEYS
 *   DATA_ENCRYPTION_KEY set   -> primary = DATA_ENCRYPTION_KEY
 *                                chain   = DATA_ENCRYPTION_PREVIOUS_KEYS,
 *                                          then APP_KEY LAST
 *
 * Invariants, each one load-bearing:
 *   1. APP_KEY is appended to the read chain whenever it differs from the
 *      primary key. That is what lets an existing install adopt a dedicated
 *      key and still read every row written before adoption, with no command
 *      run at all.
 *   2. Order is preserved, blanks are dropped, duplicates are removed. The
 *      primary is always index 0.
 *   3. A malformed key is NEVER skipped silently. Skipping would present
 *      perfectly recoverable rows as unreadable mid-rotation, and the operator
 *      response to that is to clear DATA_ENCRYPTION_PREVIOUS_KEYS — which is
 *      the actual irreversible data loss. It throws instead, naming the env
 *      var at fault.
 *
 * NULL-SAFETY (mergeConfigFrom is SHALLOW)
 * ----------------------------------------
 * A consumer whose published `config/starter-kit.php` predates the
 * `encryption` block hides the package copy of that whole array, so every read
 * here returns null. Every config read below therefore has an explicit
 * fallback and the null path lands on APP_KEY — never on a null primary, which
 * would throw on every settings read. Same discipline as
 * CheckResourcePermission::ALLOW_UNRESOLVED_DEFAULT.
 *
 * SECRET HANDLING
 * ---------------
 * Key material never reaches a message, a dump or a serialized payload:
 * exception text names only the env var, `keys()` exposes a printable `source`
 * label beside the raw material, `__debugInfo()` redacts, and the object
 * serializes to nothing and re-resolves from config on wake.
 */
final class DataEncrypterFactory
{
    /**
     * Container binding produced by this factory.
     *
     * Registered as a singleton by StarterKitServiceProvider; DataCrypt is the
     * facade over it.
     */
    public const BINDING = 'sk.data_encrypter';

    /**
     * Last-resort cipher, used only when neither `starter-kit.encryption.cipher`
     * nor `app.cipher` yields a value.
     *
     * DO NOT turn this into a literal default inside config/starter-kit.php.
     * An app with a stale published config reads null there and would then
     * resolve a DIFFERENT cipher than an app without one; the fallback has to
     * live in one place that both populations reach. It also has to stay below
     * `app.cipher` in precedence so an app that switched to AES-128-CBC keeps
     * a 16-byte APP_KEY that validates.
     */
    public const DEFAULT_CIPHER = 'AES-256-CBC';

    public const PRIMARY_ENV_KEY = 'DATA_ENCRYPTION_KEY';

    public const PREVIOUS_ENV_KEY = 'DATA_ENCRYPTION_PREVIOUS_KEYS';

    public const CIPHER_ENV_KEY = 'DATA_ENCRYPTION_CIPHER';

    public const APP_ENV_KEY = 'APP_KEY';

    /**
     * Laravel's own APP_KEY-rotation list (`config('app.previous_keys')`).
     *
     * Not a key this feature introduces — it is inherited. See resolve().
     */
    public const APP_PREVIOUS_ENV_KEY = 'APP_PREVIOUS_KEYS';

    /**
     * Memoised, ordered key chain. Index 0 is the primary (write) key.
     *
     * @var list<array{source: string, key: string}>|null
     */
    private ?array $chain = null;

    private ?string $resolvedCipher = null;

    /**
     * Build the encrypter: primary key first, every other configured key behind
     * it as a decrypt-only fallback.
     *
     * @throws RuntimeException when no key is configured at all, or a
     *                          configured key is malformed for the cipher
     */
    public function make(): Encrypter
    {
        $chain = $this->resolve();

        // KitEncrypter, not Encrypter: behaviourally identical, but it carries
        // the KitOwnedEncrypter marker so EncrypterCoverage can tell an
        // encrypter the KIT built from one an app rebound over it. Nothing else
        // depends on the concrete class.
        $encrypter = new KitEncrypter($chain[0]['key'], $this->cipher());

        return $encrypter->previousKeys(array_map(
            static fn (array $entry): string => $entry['key'],
            array_slice($chain, 1),
        ));
    }

    /**
     * The ordered key chain, primary first.
     *
     * Consumed by `encryption:rekey` and `encryption:health` so they can report
     * WHICH key decrypted a row. `source` is the ONLY member that may be
     * printed, logged or serialised; `key` is raw binary key material and must
     * never leave the process.
     *
     * @return list<array{source: string, key: string}>
     *
     * @throws RuntimeException
     */
    public function keys(): array
    {
        return $this->resolve();
    }

    /**
     * Whether writes go to DATA_ENCRYPTION_KEY rather than the APP_KEY
     * fallback. False means the install is still on the legacy path where
     * `key:generate` would destroy its encrypted data.
     *
     * @throws RuntimeException
     */
    public function usingDedicatedKey(): bool
    {
        return $this->resolve()[0]['source'] === self::PRIMARY_ENV_KEY;
    }

    /**
     * Cipher for every key in the chain.
     *
     * Precedence: DATA_ENCRYPTION_CIPHER, then the app cipher (so an app that
     * changed `app.cipher` keeps a valid-length APP_KEY in the chain), then
     * self::DEFAULT_CIPHER.
     *
     * When BOTH are set they must agree. Invariant 1 puts APP_KEY at the end
     * of every chain and Illuminate's Encrypter runs ONE cipher across its
     * whole key list, so a data cipher that differs from `app.cipher` would
     * validate and decrypt the APP_KEY fallback with the wrong algorithm: a
     * 16-byte AES-128 APP_KEY fails the length check outright, and a
     * same-length CBC/GCM swap leaves every pre-adoption settings row and 2FA
     * secret unreadable while looking configured. Refusing loudly is the only
     * outcome that is not silent data loss.
     *
     * @throws RuntimeException when DATA_ENCRYPTION_CIPHER and app.cipher disagree
     */
    public function cipher(): string
    {
        if ($this->resolvedCipher !== null) {
            return $this->resolvedCipher;
        }

        $configured = $this->configString('cipher');
        $appCipher = config('app.cipher');
        $appCipher = is_string($appCipher) && trim($appCipher) !== '' ? trim($appCipher) : null;

        if ($configured !== null && $appCipher !== null && strcasecmp(trim($configured), $appCipher) !== 0) {
            throw new RuntimeException(sprintf(
                '%s [%s] does not match app.cipher [%s]. Every key in the read chain is used with a single '
                .'cipher and %s always closes that chain, so a mismatch would leave rows written under %s '
                .'unreadable. Unset %s or set it to [%s].',
                self::CIPHER_ENV_KEY,
                trim($configured),
                $appCipher,
                self::APP_ENV_KEY,
                self::APP_ENV_KEY,
                self::CIPHER_ENV_KEY,
                $appCipher,
            ));
        }

        return $this->resolvedCipher = trim($configured ?? $appCipher ?? self::DEFAULT_CIPHER);
    }

    /**
     * Decode one configured key and validate it against the cipher.
     *
     * `$raw` is used verbatim apart from an optional `base64:` prefix — exactly
     * like Illuminate's own EncryptionServiceProvider::parseKey, because a raw
     * (non-base64) APP_KEY is legal and trimming it here would silently mutate
     * a working key. Decoding is strict: PHP's non-strict mode discards
     * characters outside the base64 alphabet, which can turn a typo into a
     * plausible-looking wrong key rather than an error.
     *
     * @param  string  $raw  key material; marked sensitive so it is redacted
     *                       from stack traces
     * @param  string|null  $source  env var name used in the error message
     *
     * @throws RuntimeException naming the offending env var, never its value
     */
    public function parseKey(#[SensitiveParameter] string $raw, ?string $source = null): string
    {
        $source ??= self::PRIMARY_ENV_KEY;
        $key = $raw;

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            if ($decoded === false) {
                throw new RuntimeException(sprintf(
                    'The %s value is not valid base64. Its content is withheld here on purpose; '
                    .'fix the value in .env, or generate a new one with `php artisan encryption:key --show`. '
                    .'Do NOT clear %s while fixing this.',
                    $source,
                    self::PREVIOUS_ENV_KEY,
                ));
            }

            $key = $decoded;
        }

        $expected = $this->expectedKeyLength();
        $actual = mb_strlen($key, '8bit');

        if ($actual !== $expected) {
            throw new RuntimeException(sprintf(
                'The %s value decodes to %d bytes but cipher %s requires %d. '
                .'The value itself is withheld here on purpose. Do NOT clear %s while fixing this — '
                .'a key removed from the chain cannot be recovered.',
                $source,
                $actual,
                $this->cipher(),
                $expected,
                self::PREVIOUS_ENV_KEY,
            ));
        }

        return $key;
    }

    /**
     * Drop the memoised chain so a key swap at runtime (tests, Octane) is
     * picked up. The container binding is a singleton, so callers that swap a
     * key must flush this AND re-resolve the binding.
     */
    public function flush(): void
    {
        $this->chain = null;
        $this->resolvedCipher = null;
    }

    /**
     * Resolve and memoise the ordered chain.
     *
     * @return list<array{source: string, key: string}>
     *
     * @throws RuntimeException
     */
    private function resolve(): array
    {
        if ($this->chain !== null) {
            return $this->chain;
        }

        $dedicated = $this->configString('key');
        $appKey = $this->appKey();

        $chain = [];

        if ($dedicated !== null) {
            $chain[] = [
                'source' => self::PRIMARY_ENV_KEY,
                'key' => $this->parseKey($dedicated, self::PRIMARY_ENV_KEY),
            ];
        } elseif ($appKey !== null) {
            // Legacy path: no dedicated key configured, so behaviour stays
            // byte-identical to the pre-feature kit.
            $chain[] = [
                'source' => self::APP_ENV_KEY,
                'key' => $this->parseKey($appKey, self::APP_ENV_KEY),
            ];
        } else {
            throw new RuntimeException(sprintf(
                'No encryption key is configured: both %s and %s are empty. Set %s in .env '
                .'(`php artisan key:generate`), or adopt a dedicated key with `php artisan encryption:key`.',
                self::PRIMARY_ENV_KEY,
                self::APP_ENV_KEY,
                self::APP_ENV_KEY,
            ));
        }

        foreach ($this->previousKeyValues() as $index => $raw) {
            $source = self::PREVIOUS_ENV_KEY.'['.$index.']';

            $this->append($chain, $source, $this->parseKey($raw, $source));
        }

        // Invariant 1b (inherited, not introduced): Laravel's own `encrypter`
        // binding reads `app.previous_keys` behind APP_KEY, and until this
        // feature landed EVERY sensitive setting and every Fortify 2FA column
        // was read through exactly that binding. An install part-way through an
        // APP_KEY rotation therefore holds ciphertext only those keys can open.
        // Dropping them would not fail loudly, which is what makes it dangerous:
        // SettingService::decryptIfNeeded() swallows the DecryptException and
        // returns null, and an unreadable two_factor_secret locks the user out
        // of their own account at the challenge step.
        foreach ($this->appPreviousKeyValues() as $index => $raw) {
            $source = self::APP_PREVIOUS_ENV_KEY.'['.$index.']';

            $this->append($chain, $source, $this->parseKey($raw, $source));
        }

        // Invariant 1: APP_KEY always closes the chain when it is not already
        // in it. This is the entire backward-compatibility guarantee — without
        // it, adopting a dedicated key would orphan every previously written
        // row until `encryption:rekey` ran.
        if ($appKey !== null) {
            $this->append($chain, self::APP_ENV_KEY, $this->parseKey($appKey, self::APP_ENV_KEY));
        }

        return $this->chain = $chain;
    }

    /**
     * Append a key unless an identical one is already in the chain.
     *
     * Dedupe compares DECODED material, so `base64:...` and its raw equivalent
     * collapse to one entry and the first (most meaningful) source label wins.
     *
     * @param  list<array{source: string, key: string}>  $chain
     */
    private function append(array &$chain, string $source, #[SensitiveParameter] string $key): void
    {
        foreach ($chain as $entry) {
            if (hash_equals($entry['key'], $key)) {
                return;
            }
        }

        $chain[] = ['source' => $source, 'key' => $key];
    }

    /**
     * Raw, de-duplicated previous-key values in configured order.
     *
     * Accepts the shipped comma-separated string and an array, since a consumer
     * may set the published config key directly. Entries are trimmed (a list
     * written as `a, b` is the expected shape) and blanks are dropped. The
     * index in the returned list is what the `source` label reports, i.e. it
     * counts usable entries, not raw comma positions.
     *
     * @return list<string>
     */
    private function previousKeyValues(): array
    {
        return $this->normalizeKeyList(config('starter-kit.encryption.previous_keys'));
    }

    /**
     * Laravel's APP_PREVIOUS_KEYS list, in configured order.
     *
     * Read from `app.previous_keys`, which the framework skeleton already
     * normalises to a filtered array. Kept in the chain for backward
     * compatibility only — nothing the kit writes ever uses these keys.
     *
     * @return list<string>
     */
    private function appPreviousKeyValues(): array
    {
        return $this->normalizeKeyList(config('app.previous_keys'));
    }

    /**
     * Normalise a configured key list to trimmed, non-empty, de-duplicated
     * values in their original order.
     *
     * @return list<string>
     */
    private function normalizeKeyList(mixed $configured): array
    {
        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        if (! is_array($configured)) {
            return [];
        }

        $values = [];

        foreach ($configured as $item) {
            if (! is_string($item)) {
                continue;
            }

            $item = trim($item);

            if ($item === '' || in_array($item, $values, true)) {
                continue;
            }

            $values[] = $item;
        }

        return $values;
    }

    /**
     * Read a string from the `encryption` block, treating absent, non-string
     * and blank alike. Blank matters: stubs ship `DATA_ENCRYPTION_KEY=` empty,
     * which must resolve to the APP_KEY fallback rather than to a zero-length
     * key. The returned value is VERBATIM — only the emptiness test trims.
     */
    private function configString(string $key): ?string
    {
        $value = config('starter-kit.encryption.'.$key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }

    private function appKey(): ?string
    {
        $value = config('app.key');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }

    /**
     * Required key length in bytes for the configured cipher.
     *
     * Illuminate exposes no cipher table, so the supported lengths are probed
     * through Encrypter::supported(). Probing with NUL bytes keeps real key
     * material out of this path entirely.
     *
     * @throws RuntimeException when the cipher itself is unsupported
     */
    private function expectedKeyLength(): int
    {
        $cipher = $this->cipher();

        foreach ([16, 32] as $length) {
            if (Encrypter::supported(str_repeat("\0", $length), $cipher)) {
                return $length;
            }
        }

        throw new RuntimeException(sprintf(
            'Unsupported encryption cipher [%s]. Set %s (or `app.cipher`) to one of: '
            .'AES-128-CBC, AES-256-CBC, AES-128-GCM, AES-256-GCM.',
            $cipher,
            self::CIPHER_ENV_KEY,
        ));
    }

    /**
     * Redact key material from `dd()`, `dump()` and `var_dump()`.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'cipher' => $this->resolvedCipher,
            'keys' => array_map(
                static fn (array $entry): array => [
                    'source' => $entry['source'],
                    'key' => '[redacted]',
                ],
                $this->chain ?? [],
            ),
        ];
    }

    /**
     * Serialize to nothing so key material can never ride along in a queue
     * payload or a cached closure. State is re-resolved from config on wake.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        $this->chain = null;
        $this->resolvedCipher = null;
    }
}
