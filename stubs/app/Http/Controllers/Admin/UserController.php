<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Media\Actions\ClearMediaAction;
use App\Domain\Media\Actions\UploadMediaAction;
use App\Domain\Role\Queries\RoleSelectOptionsQuery;
use App\Domain\User\Actions\CreateUserAction;
use App\Domain\User\Actions\DeleteUserAction;
use App\Domain\User\Actions\UpdateUserAction;
use App\Domain\User\DTOs\UserDTO;
use App\Domain\User\Queries\UserDatatableQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\User\StoreUserRequest;
use App\Http\Requests\Admin\User\UpdateUserRequest;
use App\Http\Requests\UploadAvatarRequest;
use App\Http\Resources\Admin\User\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

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
    public function create(): Response
    {
        return Inertia::render('Admin/Users/Create');
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
        $user->load(['roles', 'media']);

        return to_api(['user' => new UserResource($user)]);
    }

    /**
     * Show the form for editing the specified user.
     */
    public function edit(User $user): Response
    {
        return Inertia::render('Admin/Users/Edit', [
            'userId' => $user->id,
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
        $action->execute($user, $request, 'avatar');

        return to_api(['avatar_url' => $user->refresh()->avatar_url], __('sk-message.avatar_uploaded'));
    }

    /**
     * Delete avatar for the specified user.
     */
    public function deleteAvatar(User $user, ClearMediaAction $action): ApiResponse|JsonResponse
    {
        $action->execute($user, 'avatar');

        return to_api(status: 204);
    }
}
