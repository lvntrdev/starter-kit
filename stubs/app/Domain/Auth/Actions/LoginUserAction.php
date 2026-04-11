<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\DTOs\LoginDTO;
use App\Domain\Shared\Actions\BaseAction;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Action: Authenticate a user via API credentials and create an access token.
 *
 * @return array{user: User, token: string}|null Returns null on failed authentication.
 */
class LoginUserAction extends BaseAction
{
    /**
     * @return array{user: User, token: string}|null
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

        $token = $user->createToken('auth-token')->accessToken;

        return [
            'user' => $user,
            'token' => $token,
        ];
    }
}
