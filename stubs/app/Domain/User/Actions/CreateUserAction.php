<?php

namespace App\Domain\User\Actions;

use App\Domain\Shared\Actions\BaseAction;
use App\Domain\User\DTOs\UserDTO;
use App\Domain\User\Events\UserCreated;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Action: Create a new user.
 * Single-purpose action — receives a DTO, persists via Eloquent.
 * Dispatches UserCreated event on success.
 *
 * The user row + role sync run inside a transaction so a failed role
 * assignment does not leave a role-less user behind.
 */
class CreateUserAction extends BaseAction
{
    /**
     * Execute the action.
     */
    public function execute(UserDTO $dto): User
    {
        $user = DB::transaction(function () use ($dto): User {
            $user = User::create($dto->toArray());

            if ($dto->role !== null) {
                $user->syncRoles([$dto->role]);
            }

            return $user;
        });

        UserCreated::dispatch($user, Auth::id());

        return $user;
    }
}
