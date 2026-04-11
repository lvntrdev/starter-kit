<?php

namespace App\Domain\User\Actions;

use App\Domain\Shared\Actions\BaseAction;
use App\Models\User;

/**
 * Action: Delete a user.
 * Includes self-deletion guard — a user cannot delete their own account.
 * Dispatches UserDeleted event on success.
 *
 * performedById is passed explicitly so this action stays HTTP-context free
 * and can be called from console commands, queue jobs, or tests.
 */
class DeleteUserAction extends BaseAction
{
    /**
     * Execute the action.
     *
     * @throws \LogicException If attempting self-deletion.
     */
    public function execute(User $user, ?string $performedById = null): bool
    {
        if ($performedById !== null && $user->id === $performedById) {
            throw new \LogicException('You cannot delete your own account.');
        }

        return (bool) $user->delete();
    }
}
