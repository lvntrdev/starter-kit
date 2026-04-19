<?php

namespace App\Policies;

use App\Enums\RoleEnum;
use App\Models\User;

/**
 * Authorization rules for actions on User records.
 *
 * All cross-user abilities enforce rank hierarchy on top of the permission
 * check: the actor must outrank or match the target role (lowest sort_order
 * wins). system_admin always bypasses the rank check. Actors with a direct
 * permission but no role are treated as the lowest rank.
 */
class UserPolicy
{
    /**
     * Viewing a user's profile / files.
     */
    public function view(User $actor, User $user): bool
    {
        if ($actor->is($user)) {
            return true;
        }

        if (! $this->canManage($actor, $user)) {
            return false;
        }

        return $actor->can('users.read') || $actor->can('users.update');
    }

    /**
     * Mutating a user's profile / files / avatar.
     */
    public function update(User $actor, User $user): bool
    {
        if ($actor->is($user)) {
            return true;
        }

        if (! $this->canManage($actor, $user)) {
            return false;
        }

        return $actor->can('users.update');
    }

    /**
     * Deleting a user or their owned media.
     */
    public function delete(User $actor, User $user): bool
    {
        if ($actor->is($user)) {
            return true;
        }

        if (! $this->canManage($actor, $user)) {
            return false;
        }

        return $actor->can('users.delete');
    }

    /**
     * Rank hierarchy guard — actor must outrank or equal target.
     *
     * system_admin bypasses. A role-less actor is treated as the lowest
     * possible rank so they cannot manage anyone but themselves (the self
     * short-circuit in the ability methods already handles self-edits).
     */
    private function canManage(User $actor, User $target): bool
    {
        if ($actor->hasRole(RoleEnum::SystemAdmin)) {
            return true;
        }

        $actorMinSortOrder = $actor->roles->min('sort_order');
        if ($actorMinSortOrder === null) {
            return false;
        }

        $targetMinSortOrder = $target->roles->min('sort_order');
        if ($targetMinSortOrder === null) {
            return true;
        }

        return (int) $actorMinSortOrder <= (int) $targetMinSortOrder;
    }
}
