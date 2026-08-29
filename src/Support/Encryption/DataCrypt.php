<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Support\Encryption;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Facade;

/**
 * Facade over the kit's data-at-rest encrypter.
 *
 * Call sites read `DataCrypt::encryptString(...)` where they used to read
 * `Crypt::encryptString(...)`. The difference is WHICH key is used: `Crypt` is
 * bound to APP_KEY, so `php artisan key:generate` destroys everything it wrote.
 * This facade resolves DataEncrypterFactory::BINDING, whose primary key is
 * DATA_ENCRYPTION_KEY when configured and APP_KEY otherwise, with APP_KEY kept
 * in the read chain either way.
 *
 * The underlying instance is an ordinary Illuminate\Encryption\Encrypter, so
 * the API is identical to `Crypt` — including the fact that a failed decrypt
 * throws DecryptException rather than returning null.
 *
 * The binding is registered as a singleton by StarterKitServiceProvider. A
 * runtime key swap must both flush DataEncrypterFactory and clear this facade's
 * resolved instance.
 *
 * @method static string encryptString(string $value)
 * @method static string decryptString(string $payload)
 * @method static string encrypt(mixed $value, bool $serialize = true)
 * @method static mixed decrypt(string $payload, bool $unserialize = true)
 * @method static string getKey()
 * @method static array getAllKeys()
 * @method static array getPreviousKeys()
 *
 * @see Encrypter
 * @see DataEncrypterFactory
 */
class DataCrypt extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return DataEncrypterFactory::BINDING;
    }
}
