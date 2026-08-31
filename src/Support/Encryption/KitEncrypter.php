<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Support\Encryption;

use Illuminate\Encryption\Encrypter;

/**
 * The Encrypter {@see DataEncrypterFactory::make()} builds — Illuminate's, with
 * a name on it.
 *
 * Behaviourally identical to its parent; it adds no state, no method and no
 * override. It exists ONLY so that an instance resolved out of the
 * `sk.data_encrypter` binding can be recognised as one the KIT built, which a
 * plain `Encrypter` cannot be: an application that rebinds that key to its own
 * `new Encrypter($key, $cipher)` produces an object indistinguishable from this
 * one at every method.
 *
 * That distinction is what lets `encryption:health` and `encryption:rekey`
 * report "the kit did not build the encrypter serving this surface" instead of
 * guessing. Since the two are behaviourally identical, an app that DID rebind
 * with the same key is still reported as covered — only the authorship claim
 * changes.
 *
 * NOT final, deliberately. This is the class the `sk.data_encrypter` binding
 * resolves to, so it is what `DataCrypt::shouldReceive()` / `partialMock()`
 * hands to Mockery — and Mockery cannot replace the methods of a final class.
 * Sealing it would break every consumer test that fakes the data encrypter, for
 * no benefit: the class adds no state and no behaviour to seal.
 *
 * @see KitOwnedEncrypter
 * @see EncrypterCoverage
 */
class KitEncrypter extends Encrypter implements KitOwnedEncrypter {}
