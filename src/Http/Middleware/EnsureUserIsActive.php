<?php

namespace Lvntr\StarterKit\Http\Middleware;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Lvntr\StarterKit\Http\Responses\ApiResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use UnitEnum;

/**
 * Cuts an already-open session the moment the account behind it is disabled.
 *
 * The login path already refuses a non-active account (FortifyServiceProvider's
 * `authenticateUsing` closure and `LoginUserAction` both require
 * `status === 'active'`), but neither of them can reach a session that is
 * ALREADY open: an admin who deactivates a user otherwise has to wait for that
 * user's cookie to expire. This middleware closes exactly that window — on the
 * next request the disabled account is logged out.
 *
 * ── FAIL-OPEN BY DESIGN ─────────────────────────────────────────────────────
 *
 * This is the one deliberately TIGHTENING piece of the kit's request pipeline,
 * and it ships into installs whose `users.status` column the kit does not
 * control. A mass lockout is a far worse outcome than one extra request served
 * to an account that was disabled a second ago, so every ambiguous input is
 * resolved by PASSING THE REQUEST THROUGH. Concretely, the request continues
 * when:
 *
 *   - no guard in `starter-kit.security.active_status_guards` has a user
 *     (guests, and any guard the operator did not list);
 *   - a listed guard is not declared under `auth.guards` at all, or throws
 *     while resolving (an unresolvable guard cannot have authenticated anyone,
 *     so skipping it leaks nothing);
 *   - the authenticated model carries no `status` attribute — a consumer with a
 *     custom user model, or a query that narrowed the SELECT, must never be
 *     locked out of their own panel;
 *   - `status` is null, a bool, or any other value that does not normalise to a
 *     string;
 *   - the normalised value is NOT in the operator's deny-list, INCLUDING
 *     unknown strings. The middleware can only ever block a status that was
 *     explicitly listed.
 *
 * That last point is the whole contract: this class never infers "not active
 * therefore blocked". It is strictly weaker than the login-time check on
 * purpose — `active`/`inactive`/`banned` is what the shipped `userStatus`
 * definition produces, but nothing stops an install from holding a fourth
 * value that means something perfectly benign.
 *
 * `starter-kit.security.enforce_active_status = false` is the kill switch and
 * short-circuits the whole class.
 *
 * ── TERMINATION SHAPE ───────────────────────────────────────────────────────
 *
 *   - API / JSON request: 403 in the kit's documented ApiResponse envelope,
 *     built here rather than thrown so the shape does not depend on the
 *     consumer having registered ApiExceptionHandler.
 *   - Web request: the stateful guard is logged out, the session invalidated
 *     and the CSRF token regenerated, then a redirect to the named `login`
 *     route carrying the same `sk-auth.inactive` copy the login-time block
 *     uses.
 *   - Web request where the credential CANNOT be cut (a token guard reached
 *     through the web group) or where no `login` route exists: a plain 403.
 *     Redirecting in either case would loop, because the next request would
 *     arrive with the very same credential still attached.
 *
 * Wired by StarterKitServiceProvider::boot() as the `sk.active` alias and
 * appended to the `web` and `api` groups, so an existing install picks it up on
 * `composer update` without touching bootstrap/app.php.
 */
class EnsureUserIsActive
{
    /**
     * Attribute consulted on the authenticated model.
     */
    public const STATUS_ATTRIBUTE = 'status';

    /**
     * Translation key for the "your account is disabled" copy. Shared with the
     * login-time block in FortifyServiceProvider so both paths say the same
     * thing.
     */
    public const MESSAGE_KEY = 'sk-auth.inactive';

    /**
     * Enforcement default, used when the config key is absent.
     *
     * mergeConfigFrom merges TOP-LEVEL keys only. A `config/starter-kit.php`
     * published before this release carries no `security` key, so it inherits
     * the vendor block whole; one that carries a PARTIAL `security` array
     * replaces the vendor one for every nested key it does not repeat. Every
     * read below therefore falls back to a constant that reproduces the literal
     * shipped in the config file exactly, so both populations resolve the same
     * value. Do not let the two drift.
     */
    public const ENFORCE_DEFAULT = true;

    /**
     * Deny-list default.
     *
     * Deliberately limited to the two non-active values the shipped
     * `userStatus` definition actually produces
     * (stubs/database/seeders/_02_DefinitionSeeder.php). Anything else —
     * including values such as `suspended` that other apps use — is an operator
     * decision and must be added explicitly; defaulting to a status the kit
     * never writes would only add lockout surface.
     *
     * @var list<string>
     */
    public const DENIED_DEFAULT = ['inactive', 'banned'];

    /**
     * Guards consulted for an authenticated user.
     *
     * `api` is listed even though Laravel's default `auth.php` no longer
     * declares it — StarterKitServiceProvider::configurePassport() synthesises
     * it when Passport is installed. A guard that does not exist is skipped.
     *
     * @var list<string>
     */
    public const GUARDS_DEFAULT = ['web', 'api'];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! self::enforcementEnabled()) {
            return $next($request);
        }

        $denied = self::deniedStatuses();

        if ($denied === []) {
            return $next($request);
        }

        foreach ($this->guards() as $name) {
            $guard = $this->resolveGuard($name, $request);

            if ($guard === null) {
                continue;
            }

            $user = $this->userFrom($guard);

            if ($user === null) {
                continue;
            }

            $status = $this->statusOf($user);

            // null: no status attribute, or a value that does not normalise.
            // Unknown string: not this middleware's business to block.
            if ($status === null || ! in_array($status, $denied, true)) {
                continue;
            }

            return $this->cutAccess($request, $guard);
        }

        return $next($request);
    }

    // ─── Configuration ──────────────────────────────────────────────────────
    //
    // The three helpers below are PUBLIC STATIC on purpose: RevokeUserAccessAction
    // decides whether a status change should drop the account's tokens and
    // database sessions, and it must answer "is this status denied?" with the
    // exact same switch, deny-list and normalisation this middleware uses —
    // stale-published-config fallbacks included. A second copy of that logic
    // would drift, and a drift here means the request pipeline and the token
    // revocation disagree about which accounts are cut off.

    /**
     * The kill switch. An absent key (stale published config) resolves to the
     * documented default.
     */
    public static function enforcementEnabled(): bool
    {
        $configured = config('starter-kit.security.enforce_active_status');

        if ($configured === null) {
            return self::ENFORCE_DEFAULT;
        }

        return (bool) $configured;
    }

    /**
     * Normalised deny-list. An empty or non-array value disables the check
     * rather than falling back to the default — an operator who deliberately
     * emptied the list means "block nothing".
     *
     * @return list<string>
     */
    public static function deniedStatuses(): array
    {
        $configured = config('starter-kit.security.active_status_denied');

        if ($configured === null) {
            $configured = self::DENIED_DEFAULT;
        }

        if (! is_array($configured)) {
            return [];
        }

        $denied = [];

        foreach ($configured as $value) {
            $normalised = self::normalizeStatus($value);

            if ($normalised !== null && $normalised !== '') {
                $denied[] = $normalised;
            }
        }

        return array_values(array_unique($denied));
    }

    /**
     * Guard names to consult, in order.
     *
     * @return list<string>
     */
    private function guards(): array
    {
        $configured = config('starter-kit.security.active_status_guards');

        if ($configured === null) {
            $configured = self::GUARDS_DEFAULT;
        }

        if (! is_array($configured)) {
            return [];
        }

        $guards = [];

        foreach ($configured as $name) {
            if (is_string($name) && $name !== '') {
                $guards[] = $name;
            }
        }

        return array_values(array_unique($guards));
    }

    // ─── Resolution ─────────────────────────────────────────────────────────

    /**
     * Resolve a configured guard, or null when it cannot safely be consulted.
     */
    private function resolveGuard(string $name, Request $request): ?Guard
    {
        // Read the guard table as an array instead of via dot notation: a guard
        // name containing a dot would otherwise resolve to a nested key.
        $declared = config('auth.guards');

        if (! is_array($declared) || ! is_array($declared[$name] ?? null)) {
            // Never ask AuthManager for a guard the app has not declared —
            // Auth::guard() throws for an unknown name.
            return null;
        }

        try {
            $guard = Auth::guard($name);
        } catch (Throwable) {
            // Undefined driver, missing provider, uninstalled package. An
            // unusable guard cannot have authenticated the request.
            return null;
        }

        // A session-backed guard reached before StartSession (e.g. via the
        // `api` group, which has no session) would read a dead store and, worse,
        // could log a user in from the remember-me cookie and then write to
        // that dead store. Skip it unless a user is already set on the guard
        // (actingAs, or an earlier resolution in this request) or the request
        // genuinely carries a session.
        if ($guard instanceof StatefulGuard && ! $guard->hasUser() && ! $request->hasSession()) {
            return null;
        }

        return $guard;
    }

    private function userFrom(Guard $guard): ?Authenticatable
    {
        try {
            return $guard->user();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Read the account status without ever touching a relation or a mutator
     * that could throw.
     */
    private function statusOf(Authenticatable $user): ?string
    {
        try {
            if ($user instanceof Model) {
                // Only a REAL loaded attribute participates. Going through
                // getAttribute() blindly would let Model::isRelation() see a
                // `status()` relation method and lazy-load it on every request;
                // gating on getAttributes() keeps that off the hot path and
                // makes "custom user model without a status column" an explicit
                // pass-through. Casts (including enum casts) still apply,
                // because the value is then read back through getAttribute().
                if (! array_key_exists(self::STATUS_ATTRIBUTE, $user->getAttributes())) {
                    return null;
                }

                $value = $user->getAttribute(self::STATUS_ATTRIBUTE);
            } else {
                $value = isset($user->{self::STATUS_ATTRIBUTE})
                    ? $user->{self::STATUS_ATTRIBUTE}
                    : null;
            }
        } catch (Throwable) {
            return null;
        }

        return self::normalizeStatus($value);
    }

    /**
     * Reduce a status value to a comparable string, or null when it is not
     * comparable. Comparison is case-insensitive and trimmed so a column
     * holding `Inactive` still matches a deny-list entry of `inactive`; this
     * only ever widens the match to case variants of values the operator
     * already listed.
     */
    public static function normalizeStatus(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        } elseif ($value instanceof UnitEnum) {
            $value = $value->name;
        }

        // Bool is intentionally excluded: `true`/`false` would stringify to
        // "1"/"" and collide with an integer status column.
        if ($value === null || is_bool($value)) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        } elseif (is_object($value) && method_exists($value, '__toString')) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        return mb_strtolower(trim($value));
    }

    // ─── Termination ────────────────────────────────────────────────────────

    /**
     * NOT named `terminate()`. Kernel::terminateMiddleware() calls
     * `$instance->terminate($request, $response)` on any middleware where
     * `method_exists()` says so — and method_exists() does not care about
     * visibility, so a PRIVATE terminate() here would fatal on every single
     * request that passed through this class. `handle` and `terminate` are
     * reserved on a middleware; do not reintroduce either as a helper name.
     */
    private function cutAccess(Request $request, Guard $guard): Response
    {
        $message = (string) __(self::MESSAGE_KEY);

        if ($this->expectsJson($request)) {
            return ApiResponse::error($message, 403)->toResponse($request);
        }

        $loggedOut = false;

        if ($guard instanceof StatefulGuard) {
            try {
                $guard->logout();
                $loggedOut = true;
            } catch (Throwable) {
                $loggedOut = false;
            }
        }

        if ($request->hasSession()) {
            try {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            } catch (Throwable) {
                // A half-started session must not turn an access cut into a 500.
            }
        }

        $loginUrl = $this->loginUrl();

        // Redirecting while the credential is still attached would bounce the
        // client between the login route and this middleware forever.
        if (! $loggedOut || $loginUrl === null) {
            return response($message, 403);
        }

        // No session means nothing can carry the copy to the login page, and
        // RedirectResponse::with() would flash onto a Store that was never
        // started. Send the bare redirect instead.
        if (! $request->hasSession()) {
            return redirect()->to($loginUrl);
        }

        // guest() stores the intended URL, matching what Laravel's own
        // Authenticate middleware does; `status` is the prop Auth/Login.vue
        // already renders.
        return redirect()->guest($loginUrl)->with('status', $message);
    }

    /**
     * Mirrors ApiExceptionHandler's own JSON detection so both error paths
     * classify a request identically. Inertia requests are NOT JSON by this
     * test (they send `Accept: text/html`), so the admin panel takes the
     * redirect branch.
     */
    private function expectsJson(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    /**
     * Only the NAMED `login` route is accepted — guessing `/login` in an app
     * that does not have one would turn an access cut into a 404.
     *
     * Resolution goes straight through the URL generator rather than gating on
     * Route::has() first: both read the same name lookup, so the extra check
     * buys nothing, and RouteNotFoundException is the authoritative answer to
     * "can I actually build this URL".
     */
    private function loginUrl(): ?string
    {
        try {
            return route('login');
        } catch (Throwable) {
            return null;
        }
    }
}
