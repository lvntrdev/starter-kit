<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Support\Encryption;

use Lvntr\StarterKit\StarterKitServiceProvider;

/**
 * Marks an encrypter the KIT installed, as opposed to one the application did.
 *
 * The distinction cannot be recovered from behaviour. An app that called
 * `Fortify::encryptUsing()` with an encrypter built on the same key is
 * byte-for-byte indistinguishable from the kit's own shim at every method, and
 * the two are not equivalent to report on: the kit can promise what its own
 * shim does after `encryption:key` or `encryption:rekey` rewrites a key, and can
 * promise nothing at all about an object it did not build and does not control.
 *
 * Purely declarative — no method, no behaviour, no key material. It exists so
 * {@see EncrypterCoverage} can say "the kit did not build this" without
 * guessing, and so `encryption:health` and `encryption:rekey` can be honest
 * about the difference instead of reporting a confident false positive.
 *
 * @see EncrypterCoverage
 * @see StarterKitServiceProvider::dataEncrypterProxy()
 */
interface KitOwnedEncrypter {}
