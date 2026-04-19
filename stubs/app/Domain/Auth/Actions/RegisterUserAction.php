<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\DTOs\RegisterDTO;
use App\Domain\Shared\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Laravel\Fortify\Features;

/**
 * Action: Register a new user via API.
 *
 * When email verification is enabled, no access token is issued on
 * registration — the caller must verify the email first and then log in.
 * Otherwise an access token is returned immediately (current behavior).
 *
 * @return array{user: User, token?: string, requires_verification: bool}
 */
class RegisterUserAction extends BaseAction
{
    /**
     * @return array{user: User, token?: string, requires_verification: bool}
     */
    public function execute(RegisterDTO $dto): array
    {
        $user = User::create([
            ...$dto->toArray(),
            'status' => 'active',
        ]);

        if (Features::enabled(Features::emailVerification())) {
            // Trigger Fortify's Registered listener so the verification
            // notification is dispatched. No token until the user verifies.
            event(new Registered($user));

            return [
                'user' => $user,
                'requires_verification' => true,
            ];
        }

        $token = $user->createToken('auth-token')->accessToken;

        return [
            'user' => $user,
            'token' => $token,
            'requires_verification' => false,
        ];
    }
}
