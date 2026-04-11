<?php

namespace App\Domain\Auth\Actions;

use App\Domain\Auth\DTOs\RegisterDTO;
use App\Domain\Shared\Actions\BaseAction;
use App\Models\User;

/**
 * Action: Register a new user via API and create an access token.
 *
 * @return array{user: User, token: string}
 */
class RegisterUserAction extends BaseAction
{
    /**
     * @return array{user: User, token: string}
     */
    public function execute(RegisterDTO $dto): array
    {
        $user = User::create([
            ...$dto->toArray(),
            'status' => 'active',
        ]);

        $token = $user->createToken('auth-token')->accessToken;

        return [
            'user' => $user,
            'token' => $token,
        ];
    }
}
