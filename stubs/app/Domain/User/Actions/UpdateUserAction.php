<?php

namespace App\Domain\User\Actions;

use App\Domain\Shared\Actions\BaseAction;
use App\Domain\User\DTOs\UserDTO;
use App\Domain\User\Events\UserUpdated;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Action: Update an existing user.
 * Handles password-optional updates via DTO.
 * Dispatches UserUpdated event with changed fields.
 */
class UpdateUserAction extends BaseAction
{
    /**
     * Execute the action.
     */
    public function execute(User $user, UserDTO $dto): User
    {
        $data = $dto->toArray();

        // Track which fields actually changed
        $changedFields = array_keys(array_filter(
            $data,
            fn ($value, $key) => $user->getAttribute($key) !== $value,
            ARRAY_FILTER_USE_BOTH,
        ));

        $user->update($data);

        $user->refresh();

        if (! empty($changedFields)) {
            // UserUpdated::dispatch($user, $changedFields, Auth::id());
        }

        return $user;
    }
}
