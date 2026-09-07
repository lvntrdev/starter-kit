<?php

declare(strict_types=1);

namespace Lvntr\StarterKit\Console\Doctor\Checks;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Lvntr\StarterKit\Console\Doctor\DoctorCheck;
use Lvntr\StarterKit\Console\Doctor\DoctorReport;
use Lvntr\StarterKit\Http\Middleware\CheckResourcePermission;

/**
 * Which routes are riding CheckResourcePermission's UNRESOLVED axis today —
 * the ones that currently pass with a throttled warning because no permission
 * can be derived from their name — so the flip of
 * `starter-kit.permissions.allow_unresolved` to `false` (scheduled, see
 * CheckResourcePermission::ALLOW_UNRESOLVED_DEFAULT) lands on a list the
 * consumer already reviewed, not a surprise wave of 403s.
 *
 * Deliberately a FAIL, not a WARN, whenever any such route exists — regardless
 * of the CURRENT value of `allow_unresolved`. The flag controls request-time
 * behavior only; whether the consumer should be told about the gap does not
 * depend on it. A route excluded here is excluded because it genuinely is not
 * on the UNRESOLVED axis: it carries an explicit permission argument (which
 * only ever touches the UNMAPPED axis, never this one) or it is declared
 * under `unrestricted_routes` (deliberately permission-free, not a gap).
 *
 * Uses `CheckResourcePermission::resolutionFor()` — the single source of
 * truth for the route-name → permission rule — rather than re-parsing route
 * names here. A second copy would drift from the middleware's own rule and
 * report a gate that does not exist.
 */
class UnresolvedRouteCheck implements DoctorCheck
{
    /**
     * Route middleware names (aliases and FQCN) that put a route on the
     * UNRESOLVED / UNMAPPED axes at all. Anything else is not this check's
     * concern.
     *
     * @var list<string>
     */
    private const PERMISSION_MIDDLEWARE = [
        'check.permission',
        'check.resource.permission',
        CheckResourcePermission::class,
    ];

    /**
     * How many routes are named in the message before it truncates.
     */
    private const MAX_REPORTED = 8;

    public function name(): string
    {
        return (string) __('sk-doctor.unresolved_route.name');
    }

    public function run(): DoctorReport
    {
        $unresolved = [];

        foreach (RouteFacade::getRoutes() as $route) {
            if (! $route instanceof Route) {
                continue;
            }

            $middlewareEntry = $this->permissionMiddlewareEntry($route);

            if ($middlewareEntry === null) {
                continue;
            }

            // "check.permission:reports.read" — the explicit-argument form
            // never touches the UNRESOLVED axis (see class docblock).
            if (Str::contains($middlewareEntry, ':')) {
                continue;
            }

            if ($this->isUnrestricted($route->getName())) {
                continue;
            }

            if (CheckResourcePermission::resolutionFor($route) === null) {
                $unresolved[] = $this->describe($route);
            }
        }

        if ($unresolved === []) {
            return DoctorReport::ok(
                $this->name(),
                (string) __('sk-doctor.unresolved_route.all_resolved')
            );
        }

        $shown = array_slice($unresolved, 0, self::MAX_REPORTED);
        $suffix = count($unresolved) > self::MAX_REPORTED
            ? ' (+'.(count($unresolved) - self::MAX_REPORTED).' more)'
            : '';

        return DoctorReport::fail(
            $this->name(),
            (string) __('sk-doctor.unresolved_route.found', [
                'count' => count($unresolved),
                'routes' => implode(', ', $shown),
                'suffix' => $suffix,
            ]),
            (string) __('sk-doctor.unresolved_route.found_hint')
        );
    }

    /**
     * The raw middleware entry (e.g. "check.permission" or
     * "check.permission:reports.read") that puts this route on the
     * permission-checking path, or null if none does.
     */
    private function permissionMiddlewareEntry(Route $route): ?string
    {
        $bare = null;

        // gatherMiddleware(), not middleware(): the scaffold applies the
        // parameterless alias at the GROUP level, and a route's own explicit
        // `check.permission:reports.read` is a second entry. Returning the
        // first match found the group pass and never noticed the explicit one,
        // so an explicitly gated route was reported unresolved. Scan all
        // entries and let an argumented one win.
        foreach ((array) $route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            $name = Str::before($middleware, ':');

            if (! in_array($name, self::PERMISSION_MIDDLEWARE, true)) {
                continue;
            }

            if (Str::contains($middleware, ':') && Str::after($middleware, ':') !== '') {
                return $middleware;
            }

            $bare ??= $middleware;
        }

        return $bare;
    }

    /**
     * Mirrors CheckResourcePermission::isUnrestrictedRoute() — that method is
     * private to the middleware, so the same rule is re-applied here rather
     * than re-derived differently.
     *
     * The exemption is a UNION of two lists and both halves must be consulted:
     * the package's own PACKAGE_UNRESTRICTED_ROUTES (exact names, shipped) and
     * the consumer's `unrestricted_routes` config (Str::is wildcards). Reading
     * only the config half made this check report FAIL on a stock install for
     * system-health.run — a route the middleware exempts permanently.
     */
    private function isUnrestricted(?string $routeName): bool
    {
        if ($routeName === null || $routeName === '') {
            return false;
        }

        if (in_array($routeName, CheckResourcePermission::PACKAGE_UNRESTRICTED_ROUTES, true)) {
            return true;
        }

        $patterns = config('starter-kit.permissions.unrestricted_routes', []);

        if (! is_array($patterns)) {
            return false;
        }

        $patterns = array_values(array_filter($patterns, 'is_string'));

        return $patterns !== [] && Str::is($patterns, $routeName);
    }

    private function describe(Route $route): string
    {
        $name = $route->getName();

        if ($name !== null && $name !== '') {
            return $name;
        }

        // HEAD is auto-registered alongside every GET and only adds noise to a
        // report a human reads; keep the leading slash so the URI is copyable.
        $methods = array_values(array_diff($route->methods(), ['HEAD']));

        if ($methods === []) {
            $methods = $route->methods();
        }

        return implode('|', $methods).' /'.ltrim($route->uri(), '/');
    }
}
