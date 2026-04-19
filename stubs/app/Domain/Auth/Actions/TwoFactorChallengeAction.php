<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Shared\Actions\BaseAction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

/**
 * Action: Complete the API two-factor challenge issued by LoginUserAction.
 *
 * Validates either a TOTP `code` or a one-shot `recovery_code`, consumes the
 * one-time challenge record, and issues an access token on success.
 *
 * @return array{user: User, token: string}|null
 */
class TwoFactorChallengeAction extends BaseAction
{
    public function __construct(private TwoFactorAuthenticationProvider $provider) {}

    /**
     * @return array{user: User, token: string}|null
     */
    public function execute(string $challenge, ?string $code, ?string $recoveryCode): ?array
    {
        $cacheKey = LoginUserAction::challengeKey($challenge);
        $userId = Cache::get($cacheKey);

        if ($userId === null) {
            return null;
        }

        $user = User::find($userId);

        if (! $user || $user->two_factor_secret === null || $user->two_factor_confirmed_at === null) {
            Cache::forget($cacheKey);

            return null;
        }

        if ($user->status !== 'active') {
            Cache::forget($cacheKey);

            return null;
        }

        if ($code !== null && $code !== '') {
            $valid = $this->provider->verify(
                Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
                $code,
            );

            if (! $valid) {
                return null;
            }
        } elseif ($recoveryCode !== null && $recoveryCode !== '') {
            $match = collect($user->recoveryCodes())->first(
                fn (string $stored) => hash_equals($stored, $recoveryCode),
            );

            if ($match === null) {
                return null;
            }

            $user->replaceRecoveryCode($match);
        } else {
            return null;
        }

        Cache::forget($cacheKey);

        $token = $user->createToken('auth-token')->accessToken;

        return [
            'user' => $user->refresh(),
            'token' => $token,
        ];
    }
}
