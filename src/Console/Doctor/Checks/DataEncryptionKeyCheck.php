<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Console\Doctor\Checks;

use Lvntr\StarterKit\Console\Commands\EncryptionHealthCommand;
use Lvntr\StarterKit\Console\Doctor\DoctorCheck;
use Lvntr\StarterKit\Console\Doctor\DoctorReport;
use Lvntr\StarterKit\Support\DocsLink;
use Lvntr\StarterKit\Support\Encryption\DataEncrypterFactory;
use Throwable;

/**
 * Is this install's data-at-rest encryption hostage to `APP_KEY`?
 *
 * Sensitive `settings.value` rows and the Fortify 2FA secret plus recovery
 * codes are encrypted by {@see DataEncrypterFactory}. Without a dedicated
 * `DATA_ENCRYPTION_KEY` the primary key is `APP_KEY`, and a single
 * `php artisan key:generate` — the most ordinary thing an operator does on a
 * server migration or a fresh deploy — makes every one of those values
 * permanently unreadable. Silently: SettingService swallows the
 * DecryptException and returns null, and an unreadable `two_factor_secret`
 * locks the user out at the challenge step.
 *
 * That state is a WARN, never a FAIL: it is the kit's historical default and
 * every existing install is in it. Failing would make `sk:doctor` red on a
 * perfectly working app and train operators to ignore the whole report.
 *
 * CONFIG ONLY — no table is touched and nothing is decrypted. Two reasons: the
 * {@see DoctorCheck} interface budgets ~2 seconds per check, and a check that
 * decrypts would put ciphertext on a path that exists to print things. Counting
 * which rows ride which key is `encryption:health`'s job, and this check's hint
 * points there.
 *
 * @see DataEncrypterFactory for the key-resolution contract
 * @see EncryptionHealthCommand
 */
class DataEncryptionKeyCheck implements DoctorCheck
{
    public function name(): string
    {
        return (string) __('sk-doctor.data_encryption_key.name');
    }

    public function run(): DoctorReport
    {
        $factory = app(DataEncrypterFactory::class);

        // Re-derive rather than trust a memo built earlier in this process: the
        // check must describe the configuration as it stands right now.
        $factory->flush();

        try {
            $usingDedicatedKey = $factory->usingDedicatedKey();
        } catch (Throwable $e) {
            // The chain does not resolve at all — no key configured, or one is
            // malformed for the cipher. Every encrypted read is already
            // throwing, so this IS a failure. The factory's messages name the
            // offending env var and never its value.
            return DoctorReport::fail(
                $this->name(),
                (string) __('sk-doctor.data_encryption_key.chain_unresolved', ['error' => $e->getMessage()]),
                (string) __('sk-doctor.data_encryption_key.chain_unresolved_hint', [
                    'previous_key' => DataEncrypterFactory::PREVIOUS_ENV_KEY,
                ])
            );
        }

        if (! $usingDedicatedKey) {
            return DoctorReport::warn(
                $this->name(),
                (string) __('sk-doctor.data_encryption_key.no_dedicated_key', [
                    'primary_key' => DataEncrypterFactory::PRIMARY_ENV_KEY,
                    'app_key' => DataEncrypterFactory::APP_ENV_KEY,
                ]),
                (string) __('sk-doctor.data_encryption_key.no_dedicated_key_hint', [
                    'app_key' => DataEncrypterFactory::APP_ENV_KEY,
                    'docs_link' => DocsLink::to('server-migration-runbook.md'),
                ])
            );
        }

        if ($this->previousKeysConfigured()) {
            return DoctorReport::warn(
                $this->name(),
                (string) __('sk-doctor.data_encryption_key.rotation_unfinished', [
                    'primary_key' => DataEncrypterFactory::PRIMARY_ENV_KEY,
                    'previous_key' => DataEncrypterFactory::PREVIOUS_ENV_KEY,
                ]),
                (string) __('sk-doctor.data_encryption_key.rotation_unfinished_hint', [
                    'previous_key' => DataEncrypterFactory::PREVIOUS_ENV_KEY,
                ])
            );
        }

        // Deliberately scoped to what a CONFIG-only check can actually know.
        // This check reads env, never a row (the DoctorCheck contract gives it
        // ~2 seconds), so it cannot see a value written under APP_KEY before
        // the dedicated key was adopted. Claiming `key:generate` is harmless
        // here would be a claim about data this check never looked at — and an
        // operator who trusts it and rotates APP_KEY loses exactly those rows.
        // `encryption:health` is the command that reads the rows; it owns that
        // verdict.
        return DoctorReport::ok(
            $this->name(),
            (string) __('sk-doctor.data_encryption_key.dedicated_key_active', [
                'primary_key' => DataEncrypterFactory::PRIMARY_ENV_KEY,
                'app_key' => DataEncrypterFactory::APP_ENV_KEY,
            ])
        );
    }

    /**
     * Whether `DATA_ENCRYPTION_PREVIOUS_KEYS` holds anything at all.
     *
     * Deliberately reads the RAW configured value rather than counting the
     * resolved chain: an entry that de-duplicates away still sits in `.env` as
     * an unfinished rotation the operator has to close out, and warning about a
     * list that is in fact empty costs nothing next to staying quiet about one
     * that is not.
     *
     * Accepts the shipped comma-separated string and an array, the same two
     * shapes {@see DataEncrypterFactory} accepts.
     */
    private function previousKeysConfigured(): bool
    {
        $configured = config('starter-kit.encryption.previous_keys');

        if (is_array($configured)) {
            foreach ($configured as $item) {
                if (is_string($item) && trim($item) !== '') {
                    return true;
                }
            }

            return false;
        }

        return is_string($configured) && trim($configured) !== '';
    }
}
