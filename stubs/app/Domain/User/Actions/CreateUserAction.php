<?php

namespace App\Domain\User\Actions;

use App\Domain\Shared\Actions\BaseAction;
use App\Domain\User\DTOs\UserDTO;
use App\Domain\User\Events\UserCreated;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Action: Create a new user.
 * Single-purpose action — receives a DTO, persists via Eloquent.
 * Dispatches UserCreated event on success.
 */
class CreateUserAction extends BaseAction
{
    /**
     * Execute the action.
     */
    public function execute(UserDTO $dto): User
    {
        $user = User::create($dto->toArray());

        // UserCreated::dispatch($user, Auth::id());

        return $user;
    }
}
