<?php

namespace App\Domain\Setting\Actions;

use App\Domain\Setting\DTOs\AuthSettingsDTO;
use App\Domain\Shared\Actions\BaseAction;
use App\Models\Setting;
use App\Models\User;

/**
 * Action: Update authentication settings.
 *
 * When two-factor authentication is disabled, all users' 2FA data
 * is cleared so no one is left in an inconsistent state.
 */
class UpdateAuthSettingsAction extends BaseAction
{
    public function execute(AuthSettingsDTO $dto): void
    {
        $wasTwoFactorEnabled = Setting::getValue('auth.two_factor', '1') === '1';
        $isTwoFactorDisabled = $dto->twoFactor === '0';

        Setting::setGroup('auth', $dto->toArray());

        if ($wasTwoFactorEnabled && $isTwoFactorDisabled) {
            $this->revokeAllTwoFactorAuth();
        }
    }

    /**
     * Clear two-factor authentication data for all users.
     */
    private function revokeAllTwoFactorAuth(): void
    {
        User::query()
            ->whereNotNull('two_factor_confirmed_at')
            ->update([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ]);
    }
}
