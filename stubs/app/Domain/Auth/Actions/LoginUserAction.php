<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\DTOs\LoginDTO;
use App\Domain\Shared\Actions\BaseAction;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Fortify\Features;

/**
 * Action: Authenticate a user via API credentials.
 *
 * Returns a discriminated result:
 *   - ['kind' => 'token', 'user' => $user, 'token' => $token]
 *   - ['kind' => 'requires_verification', 'user' => $user]
 *   - ['kind' => 'requires_two_factor', 'challenge' => $uuid]
 *   - null on failed authentication.
 *
 * The access token is only issued when the user has cleared every gate
 * (active status, verified email if required, 2FA challenge if required).
 *
 * @return array{kind: string, user?: User, token?: string, challenge?: string}|null
 */
class LoginUserAction extends BaseAction
{
    /**
     * Challenge TTL for the API 2FA flow.
     */
    private const TWO_FACTOR_CHALLENGE_TTL = 300; // seconds (5 min)

    /**
     * @return array{kind: string, user?: User, token?: string, challenge?: string}|null
     */
    public function execute(LoginDTO $dto): ?array
    {
        if (! Auth::attempt($dto->credentials())) {
            return null;
        }

        /** @var User $user */
        $user = Auth::user();

        // Block non-active accounts (inactive, banned) — the credential check
        // must not be enough to obtain a token if the account is disabled.
        if ($user->status !== 'active') {
            Auth::logout();

            return null;
        }

        // Email verification gate.
        if (Features::enabled(Features::emailVerification()) && ! $user->hasVerifiedEmail()) {
            Auth::logout();

            return [
                'kind' => 'requires_verification',
                'user' => $user,
            ];
        }

        // Two-factor gate — issue a short-lived challenge id and require a
        // second request with a valid TOTP code or recovery code before a
        // token is issued.
        if ($this->requiresTwoFactor($user)) {
            $challenge = (string) Str::uuid();

            Cache::put(
                $this->challengeKey($challenge),
                $user->getKey(),
                self::TWO_FACTOR_CHALLENGE_TTL,
            );

            Auth::logout();

            return [
                'kind' => 'requires_two_factor',
                'challenge' => $challenge,
            ];
        }

        $token = $user->createToken('auth-token')->accessToken;

        return [
            'kind' => 'token',
            'user' => $user,
            'token' => $token,
        ];
    }

    public static function challengeKey(string $challenge): string
    {
        return "api:2fa_challenge:{$challenge}";
    }

    private function requiresTwoFactor(User $user): bool
    {
        return Features::enabled(Features::twoFactorAuthentication())
            && $user->two_factor_secret !== null
            && $user->two_factor_confirmed_at !== null;
    }
}
