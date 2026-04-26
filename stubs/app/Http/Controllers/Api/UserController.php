<?php

namespace App\Http\Controllers\Api;

use App\Domain\User\Actions\CreateUserAction;
use App\Domain\User\Actions\DeleteUserAction;
use App\Domain\User\Actions\UpdateUserAction;
use App\Domain\User\DTOs\UserDTO;
use App\Domain\User\Queries\UserDatatableQuery;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\User\StoreUserRequest;
use App\Http\Requests\Api\User\UpdateUserRequest;
use App\Http\Resources\Admin\User\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * REST API controller for user management (mobile / external clients).
 *
 * All responses follow the standard ApiResponse envelope.
 * Authentication: Passport (auth:api).
 */
class UserController extends Controller
{
    /**
     * List users with search, sort, filters and pagination.
     *
     * GET /api/v1/users?filter[status]=active&sort=-created_at&per_page=15
     *
     * Delegates to the shared UserDatatableQuery so admin and API enforce
     * the same role-hierarchy rule: non-system_admin callers cannot see
     * users whose highest role outranks their own.
     */
    public function index(Request $request, UserDatatableQuery $query): ApiResponse
    {
        return $query->response($request->user());
    }

    /**
     * Create a new user.
     *
     * POST /api/v1/users
     */
    public function store(StoreUserRequest $request, CreateUserAction $action): ApiResponse
    {
        $dto = UserDTO::fromArray($request->validated());
        $user = $action->execute($dto);

        return to_api(new UserResource($user->loadMissing('roles')), 'User created successfully.', 201);
    }

    /**
     * Show a single user.
     *
     * GET /api/v1/users/{user}
     */
    public function show(User $user): ApiResponse
    {
        Gate::authorize('view', $user);

        return to_api(new UserResource($user->loadMissing('roles')));
    }

    /**
     * Update an existing user.
     *
     * PUT/PATCH /api/v1/users/{user}
     */
    public function update(UpdateUserRequest $request, User $user, UpdateUserAction $action): ApiResponse
    {
        $dto = UserDTO::fromArray(array_merge(
            [
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'status' => $user->status,
            ],
            $request->validated(),
        ));

        $user = $action->execute($user, $dto);

        return to_api(new UserResource($user->loadMissing('roles')), 'User updated successfully.');
    }

    /**
     * Delete a user.
     *
     * DELETE /api/v1/users/{user}
     */
    public function destroy(Request $request, User $user, DeleteUserAction $action): ApiResponse|JsonResponse
    {
        Gate::authorize('delete', $user);

        $performedById = $request->user()?->id;
        if ($performedById === null) {
            throw ApiException::unauthorized();
        }

        try {
            $action->execute($user, (string) $performedById);
        } catch (\LogicException $e) {
            throw ApiException::badRequest($e->getMessage());
        }

        return to_api(status: 204);
    }
}
