# Authentication

The starter kit combines Laravel Fortify for web authentication and Passport for API authentication.

## Web Authentication

Built-in flows include:

- login
- register
- forgot password
- reset password
- email verification
- confirm password
- two-factor challenge

These screens live under `resources/js/pages/Auth/`.

When Turnstile is enabled from the settings panel, the login, register, and forgot-password forms render the shared `TurnstileWidget` and validate `cf_turnstile_response` server-side.

## Profile Security

Authenticated users also get profile security tools:

- profile info update
- password update
- two-factor settings
- recovery code display and regeneration behind password confirmation
- browser session management
- avatar upload and removal

These flows are mounted from the profile screen and related routes in `routes/web/profile-route.php`.

## Password Policy

The password policy is driven by the **Settings → Security → Password Policy** admin tab. Rules are stored as `auth.*` setting keys and applied at runtime by `PasswordValidationRules`.

| Setting key | What it enforces |
|---|---|
| `auth.password_min_length` | Minimum character count (default: `8`) |
| `auth.password_require_mixed_case` | Upper and lower case required |
| `auth.password_require_numbers` | At least one digit required |
| `auth.password_require_symbols` | At least one symbol required |

Every Fortify flow picks up the active rules automatically — registration, password reset, password confirmation, and profile password update. Admin user create/update flows also apply the same rules.

Existing users' passwords are not invalidated when the policy changes — only newly submitted passwords are measured against the current rules.

### Password expiry

Setting `auth.password_expiry_days` to a value greater than `0` enables the `EnsurePasswordNotExpired` middleware. Authenticated users whose `password_changed_at` timestamp is older than the configured number of days are redirected to a dedicated, guest-style password-expired screen (route `password.expired`) until they set a new password. Setting `0` (the default) disables expiry entirely.

`password_changed_at` is stamped automatically on every password write: registration, password reset, profile update, and admin user create/update. Existing users received a `now()` back-fill when the migration ran, so they start the expiry clock from the deployment date rather than being immediately expired.

## Runtime Rules

- inactive users cannot start a web session; the Fortify login pipeline blocks accounts whose status is not `active`
- login is rate-limited by IP and by email/IP combinations when `auth.login_throttle = '1'` (the default, strict limiter); setting it to `'0'` in Settings → Security swaps in a more generous `login-relaxed` floor instead of removing the limiter entirely — no admin setting can leave web login unthrottled. The API auth routes carry their own hardcoded `throttle:5,1` middleware, unaffected by this setting.
- the two-factor challenge flow has its own limiter
- the two-factor challenge is **single-use** — any wrong code, empty submit, or invalid recovery code immediately invalidates the challenge id; the client must re-login to obtain a fresh one
- the forgot-password POST route receives Turnstile middleware dynamically when the route is matched
- **self-delete is blocked on the API.** `UserPolicy::delete` returns `false` when actor === target, so `DELETE /api/v1/users/{self}` returns 403 even for users holding `users.delete`. The only supported self-removal flow is the password-confirmed Fortify path in the Profile UI.

### Cutting off an account that is already signed in

The login-time check above cannot reach a session that is *already open* — an admin who deactivates a user would otherwise wait for that user's cookie to expire. Two pieces close that window.

**`EnsureUserIsActive` middleware.** Registered by `StarterKitServiceProvider::boot()` as the `sk.active` alias and appended to the `web` and `api` groups, so an existing install picks it up on `composer update` without touching `bootstrap/app.php`. On every request it reads `status` on the authenticated model and terminates when the value is on the operator's deny-list:

- **API / JSON request** → `403` in the kit's documented `ApiResponse` envelope (built in the middleware, so the shape does not depend on `ApiExceptionHandler` being registered).
- **Web request** → the stateful guard is logged out, the session invalidated, the CSRF token regenerated, then a redirect to the named `login` route carrying the same `sk-auth.inactive` copy the login-time block uses.
- **Web request whose credential cannot be cut** (a token guard reached through the `web` group), or an app with no `login` route → a plain `403`. Redirecting would loop, because the next request arrives with the same credential attached.

**It is deliberately fail-open.** It ships into apps whose `users.status` column the kit does not control, and a mass lockout is far worse than one extra request served to an account disabled a second ago. The request passes through whenever: no listed guard has a user; a listed guard is not declared under `auth.guards` or throws while resolving; the model carries no `status` attribute; `status` is null, a bool, or anything that does not normalise to a string; or the normalised value is simply **not** on the deny-list — unknown strings included. The middleware never infers "not active, therefore blocked"; it can only block a status that was explicitly listed.

**`RevokeUserAccessAction`.** The middleware only acts on requests that pass through the `web`/`api` groups. When a user's normalised status *transitions into* a denied value, this action additionally revokes Passport access **and** refresh tokens, unredeemed authorization/device codes (which could otherwise still be exchanged for a fresh access token), and the user's database session rows. It fires on a real transition only — editing the name of a user who has been inactive for a year revokes nothing — and never throws into the caller, because the status write has already committed.

**Configuration** — `config/starter-kit.php`, `security` block:

| Key | Default | What it does |
|---|---|---|
| `security.enforce_active_status` | `true` | Kill switch. `false` short-circuits the middleware **and** the token revocation. |
| `security.active_status_denied` | `['inactive', 'banned']` | The only statuses that terminate a session. Limited on purpose to the two non-active values the shipped `userStatus` definition produces. |
| `security.active_status_guards` | `['web', 'api']` | Guards inspected on each request. |
| `security.csp_extra_origins` | `[]` | Extra origins appended to the kit's Content-Security-Policy header. |

> `mergeConfigFrom` merges **top-level** keys only. A `config/starter-kit.php` published before this release has no `security` key at all and inherits the vendor block whole; a file carrying a *partial* `security` array replaces the vendor one for every nested key it omits. The middleware therefore falls back to class constants (`EnsureUserIsActive::ENFORCE_DEFAULT` / `::DENIED_DEFAULT` / `::GUARDS_DEFAULT`) that reproduce the shipped literals exactly, so both populations resolve the same values.

## API Authentication

Passport powers the API side:

- personal access tokens
- `POST /api/v1/auth/register` and `POST /api/v1/auth/login` are public and throttled
- `POST /api/v1/auth/two-factor-challenge` is public and throttled
- `POST /api/v1/auth/logout` and `GET /api/v1/auth/me` require `auth:api`

### API Auth Flow

- `register` returns `201` with `{ user, token }` only when email verification is disabled
- when email verification is enabled, `register` returns `201` with `{ user, requires_verification: true }` and no token
- `login` can return `{ user, token }`, `{ requires_verification: true }`, or `{ requires_two_factor: true, challenge }`
- `two-factor-challenge` completes the API 2FA flow with either `code` or `recovery_code` and returns `{ user, token }` on success
- clients should branch on `requires_verification` and `requires_two_factor` instead of assuming every successful auth response contains a token

## Passport Configuration

Token lifetimes and the scope catalog are set under the `passport` key in `config/starter-kit.php` and applied by `StarterKitServiceProvider` at boot (a no-op when `laravel/passport` isn't installed).

| Config key (`passport.…`) | Env var | Default | Effect |
|---|---|---|---|
| `provider` | `STARTER_KIT_PASSPORT_PROVIDER` | `users` | Auth provider backing the auto-registered `api` guard. The guard is only synthesized when the app hasn't already defined `auth.guards.api` — a custom guard you defined yourself is never overridden. |
| `access_token_minutes` | `PASSPORT_TOKEN_MINUTES` | `60` | Access token TTL, applied via `Passport::tokensExpireIn()`. |
| `refresh_token_days` | `PASSPORT_REFRESH_TOKEN_DAYS` | `14` | Refresh token TTL, applied via `Passport::refreshTokensExpireIn()`. |
| `personal_token_days` | `PASSPORT_PERSONAL_TOKEN_DAYS` | `30` | Personal Access Token TTL, applied via `Passport::personalAccessTokensExpireIn()`. |
| `scopes` | — | 5 example scopes (`users.read`, `users.write`, `files.read`, `files.write`, `admin`) | Scope catalog registered with `Passport::tokensCan()`. It is also the allow-list `StoreApiTokenRequest` validates against when a PAT is created from `/admin/api-tokens` — requesting a scope outside this catalog is rejected with a validation error before it ever reaches Passport. The literal `*` is stripped from the allow-list even if present in the catalog, so a PAT can never be created with the wildcard scope from the UI. |
| `default_scopes` | — | `[]` (empty) | Scope(s) applied via `Passport::setDefaultScope()`. Only takes effect when `scopes` is non-empty. |

Two legacy env vars still take precedence when set (non-null, non-empty string):

- `PASSPORT_TOKEN_DAYS` (days) overrides `access_token_minutes` — the value is multiplied by `24 * 60` to derive minutes.
- `PASSPORT_PERSONAL_TOKEN_MONTHS` (months) overrides `personal_token_days` — the value is multiplied by `30` to derive days.

Both map to the `passport.access_token_days` / `passport.personal_token_months` config keys, which are `null` by default; leave them unset to use the minutes/days keys above.

**Scope enforcement is opt-in.** Populating `passport.scopes` alone restricts nothing — it only registers the catalog and (optionally) the default. To actually gate a route, attach Passport's own `scope`/`scopes` middleware (e.g. `->middleware('scope:users.read')`). Leaving `scopes` empty preserves Passport's implicit `*` scope, so existing clients and tokens keep working unchanged. Note that OAuth2 clients created via `/admin/api-clients` don't carry scopes at all (removed — see [API Clients & Tokens](./api-clients.md)); the scope catalog above applies to Personal Access Tokens only.

## API Clients & Tokens

The admin panel provides a UI for managing Passport OAuth2 clients and Personal Access Tokens (PATs):

- `/admin/api-clients` — list, create, update, and delete OAuth2 clients
- `/admin/api-tokens` — manage Personal Access Tokens

Client secrets and PAT values are shown exactly once in a dismissal-blocked modal at creation time and are never stored in plaintext. See [API Clients & Tokens](./api-clients.md) for the full reference.

## Notes

- use Fortify for the browser-facing auth experience
- use Passport for external or token-based API consumers
- keep web and API auth concerns separate even when they share the same user model
