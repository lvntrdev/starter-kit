<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Media\Actions\ClearMediaAction;
use App\Domain\Media\Actions\UploadMediaAction;
use App\Domain\Role\Queries\RoleSelectOptionsQuery;
use App\Domain\User\Actions\CreateUserAction;
use App\Domain\User\Actions\DeleteUserAction;
use App\Domain\User\Actions\UpdateUserAction;
use App\Domain\User\BulkActions\BulkDeleteUserAction;
use App\Domain\User\DTOs\UserDTO;
use App\Domain\User\Queries\UserBulkSelectionQuery;
use App\Domain\User\Queries\UserDatatableQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkActionRequest;
use App\Http\Requests\Admin\User\StoreUserRequest;
use App\Http\Requests\Admin\User\UpdateUserRequest;
use App\Http\Requests\UploadAvatarRequest;
use App\Http\Resources\Admin\User\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lvntr\StarterKit\Http\Bulk\BulkActionDispatcher;

/**
 * Admin panel user management controller.
 *
 * This controller is intentionally thin:
 *   - Validation → FormRequest
 *   - Data mapping → DTO
 *   - Business logic → Action
 *   - Listing / filtering → Query
 */
class UserController extends Controller
{
    /**
     * Display the user listing page.
     */
    public function index(RoleSelectOptionsQuery $roleOptions): Response
    {
        return Inertia::render('Admin/Users/Index', [
            'roleOptions' => $roleOptions->get(Auth::user()),
            // The create/edit dialogs mount UserForm outside the page tree, so
            // they cannot read the Create/Edit page props. Without this the
            // timezone select falls back to its empty default and offers only
            // the "site default" entry.
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    /**
     * Return paginated users as JSON for the DataTable component.
     */
    public function dtApi(UserDatatableQuery $query): ApiResponse
    {
        return $query->response(Auth::user());
    }

    /**
     * Show the form for creating a new user.
     */
    public function create(RoleSelectOptionsQuery $roleOptions): Response
    {
        return Inertia::render('Admin/Users/Create', [
            'roleOptions' => $roleOptions->get(Auth::user()),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    /**
     * Store a newly created user.
     */
    public function store(
        StoreUserRequest $request,
        CreateUserAction $action,
    ): RedirectResponse {
        $action->execute(UserDTO::fromArray($request->validated()));

        return back()->with('success', __('sk-message.created', ['entity' => __('sk-user.user')]));
    }

    /**
     * Return user data as JSON for form/dialog usage.
     */
    public function data(User $user): ApiResponse
    {
        Gate::authorize('view', $user);

        $user->load(['roles', 'media']);

        return to_api(['user' => new UserResource($user)]);
    }

    /**
     * Show the form for editing the specified user.
     */
    public function edit(User $user, RoleSelectOptionsQuery $roleOptions): Response
    {
        Gate::authorize('view', $user);

        return Inertia::render('Admin/Users/Edit', [
            'userId' => $user->id,
            'roleOptions' => $roleOptions->get(Auth::user()),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    /**
     * Update the specified user.
     */
    public function update(
        UpdateUserRequest $request,
        User $user,
        UpdateUserAction $action,
    ): RedirectResponse {
        $action->execute($user, UserDTO::fromArray($request->validated()));

        return back()->with('success', __('sk-message.updated', ['entity' => __('sk-user.user')]));
    }

    /**
     * Remove the specified user.
     */
    public function destroy(User $user, DeleteUserAction $action): RedirectResponse
    {
        Gate::authorize('delete', $user);

        try {
            $action->execute($user, (string) Auth::id());

            return back()->with('success', __('sk-message.deleted', ['entity' => __('sk-user.user')]));
        } catch (\LogicException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Upload avatar for the specified user.
     */
    public function uploadAvatar(UploadAvatarRequest $request, User $user, UploadMediaAction $action): ApiResponse
    {
        Gate::authorize('update', $user);

        $action->execute($user, $request, 'avatar');

        return to_api(['avatar_url' => $user->refresh()->avatar_url], __('sk-message.avatar_uploaded'));
    }

    /**
     * Delete avatar for the specified user.
     */
    public function deleteAvatar(User $user, ClearMediaAction $action): ApiResponse|JsonResponse
    {
        Gate::authorize('update', $user);

        $action->execute($user, 'avatar');

        return to_api(status: 204);
    }

    /**
     * Handle a bulk action on user records.
     *
     * POST /admin/users/bulk
     * Route name: users.bulk
     *
     * Two selection modes:
     *   - page (default): operate on the explicit `ids` the client sent.
     *   - select_all_filtered: re-resolve the FULL set matching the active
     *     filter snapshot via UserBulkSelectionQuery, which re-applies the exact
     *     same role-hierarchy visibility scope as the datatable listing — so
     *     "select all filtered" can never reach users outside the actor's
     *     hierarchy. Either way, the dispatcher still runs per-item authorize()
     *     (permission + rank + self-delete) as a second, independent gate.
     */
    public function bulk(
        BulkActionRequest $request,
        BulkDeleteUserAction $bulkDelete,
        UserBulkSelectionQuery $selectionQuery,
    ): RedirectResponse {
        $dispatcher = new BulkActionDispatcher;
        $dispatcher->register($bulkDelete);

        $actionKey = $request->validated('action');

        if (! $dispatcher->has($actionKey)) {
            return back()->with('error', __('sk-bulk.unsupported_action', ['action' => $actionKey]));
        }

        $actor = $request->user();
        $actor->loadMissing('roles');

        $capReached = false;

        if ($request->boolean('select_all_filtered')) {
            // Cross-page: re-query from the filter snapshot. The visibility
            // scope is enforced inside the query — NOT here — so it stays in
            // lockstep with the datatable (single source of truth).
            $items = $selectionQuery->resolve(
                $actor,
                $request->validated('filter_snapshot') ?? [],
            );

            // The cross-page query caps at MAX_ITEMS (no silent caps): if the
            // resolved set hit that bound, the filter matched more rows than a
            // single bulk operation processes — warn the user so the untouched
            // remainder is not mistaken for "done".
            $capReached = $items->count() === UserBulkSelectionQuery::MAX_ITEMS;
        } else {
            $items = User::query()
                ->whereIn('id', $request->validated('ids'))
                ->with('roles')
                ->get();
        }

        $result = $dispatcher->dispatch($actor, $actionKey, $items);

        $response = back()->with('success', $result['message']);

        if ($capReached) {
            $response->with('warning', __('sk-bulk.cap_reached', [
                'max' => UserBulkSelectionQuery::MAX_ITEMS,
            ]));
        }

        return $response;
    }
}
