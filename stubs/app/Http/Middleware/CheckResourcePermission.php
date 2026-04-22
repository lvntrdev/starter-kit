<?php

namespace App\Http\Middleware;

use App\Models\Permission;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dynamically check resource permissions based on route name.
 *
 * Maps route names like "admin.users.index" to permission "users.read"
 * using the last two segments as resource and action.
 *
 * Behavior when the resolved permission is NOT seeded in the database:
 *   - production → deny (AuthorizationException) to avoid silently
 *     exposing endpoints whose permission row was forgotten.
 *   - non-production → allow + log a warning so developers can seed it.
 * Super admin bypass is handled by Gate::before in AppServiceProvider.
 */
class CheckResourcePermission
{
    /**
     * Map route actions to permission abilities.
     *
     * @var array<string, string>
     */
    private const ACTION_ABILITY_MAP = [
        'index' => 'read',
        'show' => 'read',
        'dtApi' => 'read',
        'data' => 'read',
        'options' => 'read',
        'create' => 'create',
        'store' => 'create',
        'edit' => 'update',
        'update' => 'update',
        'uploadAvatar' => 'update',
        'deleteAvatar' => 'update',
        'regenerateDocs' => 'update',
        'syncPostman' => 'update',
        'syncApidog' => 'update',
        'destroy' => 'delete',
        'import' => 'import',
        'export' => 'export',
    ];

    /**
     * Handle an incoming request.
     *
     * When $permission is provided explicitly (e.g. "reports.read"),
     * it is used directly instead of resolving from the route name.
     *
     * Usage in routes:
     *   ->middleware('check.permission')           // auto-resolve from route name
     *   ->middleware('check.permission:reports.read') // explicit permission
     *
     * @param  Closure(Request): (Response)  $next
     *
     * @throws AuthorizationException
     */
    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        if (! $permission) {
            $routeName = $request->route()?->getName();

            if (! $routeName) {
                return $next($request);
            }

            $permission = $this->resolvePermission($routeName);

            // Check for sub-resource via ?type= query parameter
            // e.g. /admin/users?type=student → "users:student.read"
            if ($permission) {
                $type = $request->query('type');
                if ($type && is_string($type) && preg_match('/^[a-z0-9_]+$/i', $type)) {
                    $parts = explode('.', $permission, 2);
                    $subPermission = "{$parts[0]}:{$type}.{$parts[1]}";

                    if ($this->permissionExists($subPermission)) {
                        $permission = $subPermission;
                    }
                }
            }
        }

        if (! $permission) {
            return $next($request);
        }

        if (! $this->permissionExists($permission)) {
            if (app()->environment('production')) {
                throw new AuthorizationException('You are not authorized for this action.');
            }

            Log::warning('check.permission: resolved permission is not seeded; allowing in non-production env.', [
                'permission' => $permission,
                'route' => $request->route()?->getName(),
                'path' => $request->path(),
            ]);

            return $next($request);
        }

        $user = $request->user();

        if (! $user || ! $user->can($permission)) {
            throw new AuthorizationException('You are not authorized for this action.');
        }

        return $next($request);
    }

    /**
     * Resolve the permission string from a route name.
     *
     * "admin.users.index" → "users.read"
     * "users.store"       → "users.create"
     */
    private function resolvePermission(string $routeName): ?string
    {
        $segments = explode('.', $routeName);

        if (count($segments) < 2) {
            return null;
        }

        $action = array_pop($segments);
        $resource = array_pop($segments);

        $ability = self::ACTION_ABILITY_MAP[$action] ?? null;

        if (! $ability) {
            return null;
        }

        return "{$resource}.{$ability}";
    }

    /**
     * Check if the given permission exists in the database.
     *
     * A per-request cache is kept in the container so repeat lookups on the
     * same request stay cheap; the container instance resets between tests,
     * which avoids stale state pollution.
     */
    private function permissionExists(string $permissionName): bool
    {
        /** @var Collection<int, string> $names */
        $names = app()->has('check-permission.cache')
            ? app('check-permission.cache')
            : tap(Permission::pluck('name'), fn (Collection $c) => app()->instance('check-permission.cache', $c));

        return $names->contains($permissionName);
    }
}
