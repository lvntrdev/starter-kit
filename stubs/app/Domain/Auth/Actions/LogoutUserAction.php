<?php

namespace App\Domain\Auth\Actions;

use App\Models\User;
use Lvntr\StarterKit\Domain\Shared\Actions\BaseAction;
use Lvntr\StarterKit\Domain\User\Concerns\RevokesOAuthCredentials;

/**
 * Action: Revoke the current API access token for a user, together with the
 * refresh token bound to it.
 *
 * The policy lives in the concern on purpose - revoking the access token alone
 * leaves a live refresh token that mints a new one, and that decision belongs
 * to the kit, not to a file every consumer owns a copy of.
 *
 * @see RevokesOAuthCredentials
 */
class LogoutUserAction extends BaseAction
{
    use RevokesOAuthCredentials;

    public function execute(User $user): void
    {
        $this->revokeCurrentOAuthCredentials($user);
    }
}
