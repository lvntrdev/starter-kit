# API

The starter kit exposes a versioned JSON API under `/api/v1`. For a browsable, always-current view of that contract, use the api-dock panel at `/api-dock` (gated by the seeded `api-docs.read` permission) rather than reading route files by hand.

## Response Standard

All API responses use the shared envelope:

```json
{
    "success": true,
    "status": 200,
    "message": "Operation successful.",
    "data": {},
    "meta": {},
    "trace_id": "uuid"
}
```

Use:

- `to_api()`
- `ApiResponse`
- `ApiException`

Do not use raw `response()->json()` for normal package-style endpoints.

## Route File Structure

All API routes live under `/api/v1` with a global `throttle:api` middleware applied. Route files in `routes/api/` are loaded automatically and fall into three tiers:

**Public** (`routes/api/public-api.php`) — no authentication required. Register, login, and API two-factor challenge each carry an additional `throttle:5,1` (5 requests per minute).

**Auth-only** (`auth-route.php`, `service-route.php`) — wrapped with `auth:api`. No permission check.

**Permission-protected** (all other route files, including `user-route.php`) — wrapped with `['auth:api', 'check.permission']`. The `check.permission` middleware resolves the expected permission from the route name and verifies it against the authenticated user.

## Auth Endpoints

Public (no token required):

- `POST /api/v1/auth/register` — throttled 5/min
- `POST /api/v1/auth/login` — throttled 5/min
- `POST /api/v1/auth/two-factor-challenge` — throttled 5/min

Protected (`auth:api`):

- `POST /api/v1/auth/logout`
- `GET /api/v1/auth/me`

## Service Endpoints

Protected (`auth:api`):

- `GET /api/v1/definitions`

## Resource Endpoints

Protected (`auth:api` + `check.permission`):

- `Route::apiResource('users', UserController::class)` — defined in `routes/api/user-route.php`. The `index` action delegates to `UserDatatableQuery`, the same query class the admin panel uses, so the role-hierarchy filter (a non-`system_admin` caller cannot see higher-rank users) is enforced identically on both surfaces. See [roles-permissions.md](./roles-permissions.md#role-hierarchy-in-user-management).

## Authentication Model

API protection uses Passport and the `auth:api` guard.

Successful auth responses are no longer guaranteed to include a token:

- `register` may return `{ user, requires_verification: true }`
- `login` may return `{ requires_verification: true }` or `{ requires_two_factor: true, challenge }`
- `two-factor-challenge` exchanges the issued `challenge` plus `code` or `recovery_code` for `{ user, token }`

## Request Tracing

Every response carries a `trace_id` in the envelope and an `X-Request-ID` header.

- **`trace_id` is always server-generated** via `Str::uuid()`. It uniquely identifies the request in application logs — pass it to support when reporting an issue.
- **Client-supplied `X-Request-ID` headers are accepted as correlation metadata only** when they match `[A-Za-z0-9._-]{1,128}`. The sanitised value is logged as `client_request_id` alongside the server `trace_id`; values outside the charset or longer than 128 chars are dropped silently. The response header always carries the server-generated id, never the client-supplied one.

## CORS

The starter kit ships `fruitcake/laravel-cors` / Laravel's bundled CORS middleware with `config/cors.php`. The default `max_age` is `7200` (2 hours), so browsers cache the preflight `OPTIONS` response and SPA / mobile clients don't pay a handshake on every mutating call. Tune `allowed_origins`, `supports_credentials`, and `max_age` to match your deployment before going to production.

## Error Handling

Validation, authentication, authorization, not found, and other expected failures are normalized through the API exception layer. Unhandled 5xx errors return a generic `A server error occurred.` message regardless of `APP_DEBUG` — exception details live only in the logs and, when `APP_DEBUG=true`, in an additional `debug` block next to the envelope.
