<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Role\Actions\CreateRoleAction;
use App\Domain\Role\Actions\DeleteRoleAction;
use App\Domain\Role\Actions\SyncPermissionsAction;
use App\Domain\Role\Actions\UpdateRoleAction;
use App\Domain\Role\DTOs\RoleDTO;
use App\Domain\Role\Queries\CanManageRoleQuery;
use App\Domain\Role\Queries\GroupedPermissionsQuery;
use App\Domain\Role\Queries\RoleDatatableQuery;
use App\Domain\Role\Queries\UserGrantablePermissionsQuery;
use App\Enums\RoleEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Role\StoreRoleRequest;
use App\Http\Requests\Admin\Role\UpdateRoleRequest;
use App\Http\Resources\Admin\Role\RoleResource;
use App\Http\Responses\ApiResponse;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin panel role management controller.
 *
 * This controller is intentionally thin:
 *   - Validation → FormRequest
 *   - Data mapping → DTO
 *   - Business logic → Action
 *   - Listing / filtering → Query
 */
class RoleController extends Controller
{
    /**
     * Display the role listing page.
     */
    public function index(): Response
    {
        $user = Auth::user();

        return Inertia::render('Admin/Roles/Index', [
            'protectedRoles' => array_map(fn (RoleEnum $r) => $r->value, RoleEnum::cases()),
            'isSystemAdmin' => $user->hasRole(RoleEnum::SystemAdmin),
            'userMinSortOrder' => (int) $user->roles->min('sort_order'),
        ]);
    }

    /**
     * Return paginated roles as JSON for the DataTable component.
     */
    public function dtApi(RoleDatatableQuery $query): ApiResponse
    {
        return $query->response();
    }

    /**
     * Show the form for creating a new role.
     */
    public function create(GroupedPermissionsQuery $permissionsQuery, UserGrantablePermissionsQuery $grantableQuery): Response
    {
        return Inertia::render('Admin/Roles/Create', [
            'permissionsByGroup' => $permissionsQuery->get(),
            'availableLocales' => config('app.languages', ['en' => 'English']),
            'userPermissions' => $grantableQuery->get(Auth::user()),
        ]);
    }

    /**
     * Store a newly created role.
     */
    public function store(StoreRoleRequest $request, CreateRoleAction $action): RedirectResponse
    {
        $action->execute(RoleDTO::fromArray($request->validated()));

        return redirect()
            ->route('roles.index')
            ->with('success', __('sk-message.created', ['entity' => __('sk-role.role')]));
    }

    /**
     * Return role data as JSON for dialog usage.
     *
     * Mirrors the row-level authorization that edit() / destroy() apply:
     * the route-level roles.read permission is not enough on its own —
     * a non-system_admin caller must also outrank the target role,
     * otherwise this endpoint would leak details of protected roles.
     */
    public function data(Role $role, CanManageRoleQuery $canManageQuery): ApiResponse
    {
        if (! $canManageQuery->check(Auth::user(), $role)) {
            abort(403);
        }

        $role->load('permissions');

        return to_api(new RoleResource($role));
    }

    /**
     * Show the form for editing the specified role.
     */
    public function edit(
        Role $role,
        CanManageRoleQuery $canManageQuery,
        GroupedPermissionsQuery $permissionsQuery,
        UserGrantablePermissionsQuery $grantableQuery,
    ): Response {
        if (! $canManageQuery->check(Auth::user(), $role)) {
            abort(403);
        }

        $role->load('permissions');

        return Inertia::render('Admin/Roles/Edit', [
            'role' => (new RoleResource($role))->resolve(),
            'permissionsByGroup' => $permissionsQuery->get(),
            'availableLocales' => config('app.languages', ['en' => 'English']),
            'userPermissions' => $grantableQuery->get(Auth::user()),
        ]);
    }

    /**
     * Update the specified role.
     */
    public function update(UpdateRoleRequest $request, Role $role, UpdateRoleAction $action): RedirectResponse
    {
        $action->execute($role, RoleDTO::fromArray($request->validated()));

        return redirect()
            ->route('roles.index')
            ->with('success', __('sk-message.updated', ['entity' => __('sk-role.role')]));
    }

    /**
     * Sync permissions from config (runs RolePermissionSeeder).
     * Only accessible by system_admin users.
     */
    public function syncPermissions(SyncPermissionsAction $action): RedirectResponse
    {
        if (! Auth::user()->hasRole(RoleEnum::SystemAdmin)) {
            abort(403);
        }

        $action->execute();

        return redirect()
            ->route('roles.index')
            ->with('success', __('sk-message.permissions_synced'));
    }

    /**
     * Remove the specified role.
     */
    public function destroy(Role $role, DeleteRoleAction $action, CanManageRoleQuery $canManageQuery): RedirectResponse
    {
        if (! $canManageQuery->check(Auth::user(), $role)) {
            abort(403);
        }

        $action->execute($role, Auth::id());

        return redirect()
            ->route('roles.index')
            ->with('success', __('sk-message.deleted', ['entity' => __('sk-role.role')]));
    }
}
