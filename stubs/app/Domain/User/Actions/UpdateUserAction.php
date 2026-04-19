<?php

namespace App\Domain\User\Actions;

use App\Domain\Shared\Actions\BaseAction;
use App\Domain\User\DTOs\UserDTO;
use App\Domain\User\Events\UserUpdated;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Action: Update an existing user.
 * Handles password-optional updates via DTO.
 * Dispatches UserUpdated event with changed fields.
 *
 * The attribute update + role sync run inside a transaction so a failed
 * role change does not leave inconsistent state behind.
 */
class UpdateUserAction extends BaseAction
{
    /**
     * Execute the action.
     */
    public function execute(User $user, UserDTO $dto): User
    {
        $data = $dto->toArray();

        [$user, $changedFields] = DB::transaction(function () use ($user, $dto, $data): array {
            $changedFields = array_keys(array_filter(
                $data,
                fn ($value, $key) => $user->getAttribute($key) !== $value,
                ARRAY_FILTER_USE_BOTH,
            ));

            $user->update($data);

            if ($dto->role !== null) {
                $currentRole = $user->roles()->first()?->name;

                if ($currentRole !== $dto->role) {
                    $user->syncRoles([$dto->role]);
                    $changedFields[] = 'role';
                }
            }

            $user->refresh();

            return [$user, $changedFields];
        });

        if (! empty($changedFields)) {
            UserUpdated::dispatch($user, $changedFields, Auth::id());
        }

        return $user;
    }
}
