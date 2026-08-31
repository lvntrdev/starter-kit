<?php

namespace Lvntr\StarterKit\Domain\User\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Lvntr\StarterKit\Domain\Shared\Actions\BaseAction;
use Lvntr\StarterKit\Domain\User\DTOs\UserDTO;
use Lvntr\StarterKit\Domain\User\Events\UserUpdated;

/**
 * Action: Update an existing user.
 * Handles password-optional updates via DTO.
 * Dispatches UserUpdated event with changed fields.
 *
 * The attribute update + role sync run inside a transaction so a failed
 * role change does not leave inconsistent state behind.
 *
 * This is the ONLY write path that changes `status` after a user is created —
 * the admin panel (UserController::update) and the REST API
 * (Api\UserController::update) both funnel through here, and the remaining
 * writers (RegisterUserAction, the installer's first admin, the factory) only
 * ever CREATE an account with `active`. That is why the status-transition hook
 * lives here and nowhere else; a new write path able to change `status` must
 * call RevokeUserAccessAction the same way, or a deactivated account keeps its
 * OAuth tokens.
 */
class UpdateUserAction extends BaseAction
{
    /**
     * `new` in the default keeps `new UpdateUserAction` working for any caller
     * that does not resolve the action through the container.
     */
    public function __construct(
        private readonly RevokeUserAccessAction $revokeAccess = new RevokeUserAccessAction,
    ) {}

    /**
     * Execute the action.
     */
    public function execute(User $user, UserDTO $dto): User
    {
        $data = $dto->toArray();

        // Read BEFORE the write: $user is the same instance update() mutates.
        $previousStatus = $this->statusOf($user);

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

        // Deactivation must take the account's OTHER credentials with it — an
        // OAuth token or a database session outlives the cookie the middleware
        // can cut. Scheduled on the commit, so a rollback (this action inside an
        // outer transaction / ActionPipeline) revokes nothing; a no-op unless
        // the status actually moved onto the deny-list.
        $this->revokeAccess->execute($user, $previousStatus, $this->statusOf($user));

        if (! empty($changedFields)) {
            // Snapshot the persisted role set HERE (synchronous, correct) so a
            // queued audit listener records THIS update, not a later pivot.
            $roles = $user->roles()->pluck('name')->sort()->values()->all();

            UserUpdated::dispatch($user, $changedFields, $roles, Auth::id());
        }

        return $user;
    }

    /**
     * Raw `status` attribute, or null when the model does not carry one.
     *
     * Gated on getAttributes() rather than getAttribute() so a consumer model
     * without a status column (or a query that narrowed the SELECT) reads null
     * instead of lazy-loading a `status()` relation — the same rule
     * EnsureUserIsActive applies when it reads the attribute.
     */
    private function statusOf(User $user): mixed
    {
        return array_key_exists('status', $user->getAttributes())
            ? $user->getAttribute('status')
            : null;
    }
}
