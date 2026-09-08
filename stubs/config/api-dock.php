<?php

declare(strict_types=1);
use App\Http\Middleware\CheckApiDocsAccess;

/*
|--------------------------------------------------------------------------
| API Dock — Starter Kit defaults
|--------------------------------------------------------------------------
|
| The kit's copy of lvntr/api-dock's config, with ONE deliberate change from
| the package default: the `middleware` stack below. Everything else is the
| package default, kept verbatim so the merge stays honest — the package
| merges its own config only ONE level deep (mergeConfigFrom), so a nested
| key dropped from this file is a key the application then runs WITHOUT.
| Never delete a key here; change its value.
|
| Read `try_it.allowed_hosts` / `try_it.self_hosts` before you touch them:
| every name added there is an outbound host this server will connect to on
| a reader's behalf (SSRF surface). Both ship EMPTY on purpose — with them
| empty the proxy reaches this application and nothing else.
|
*/

return [
    'enabled' => true, // Enable the API Dock HTTP surface.
    'route_prefix' => 'api-dock', // Prefix used by all package routes.
    'scramble_api' => 'default', // Which Scramble API this package documents. 'default' is the one a bare Scramble::configure() sets up, so your route filter, servers and transformers apply here too. Name a registered API to document that one instead.
    // Host middleware applied before API Dock access checks. Applies to EVERY
    // api-dock route — the panel, the spec, the try-it proxy and the
    // credential-profile endpoints all inherit this stack (routes/api-dock.php).
    //
    // Order is load-bearing and must stay `web, auth, gate`:
    //   web   — starts the session the other two read (and brings the kit's own
    //           web-group middleware, incl. EnsureUserIsActive, so a deactivated
    //           account loses the panel with everything else).
    //   auth  — a guest is redirected to /login here. Without it the gate below
    //           still denies, but as a bare 403 on the panel URL.
    //   gate  — CheckApiDocsAccess: denies unless Gate::allows('viewApiDocs'),
    //           which the kit defines as the seeded `api-docs.read` ability
    //           (config/permission-resources.php grants it to `developer`).
    //           Fails CLOSED: an unseeded ability denies rather than 500s.
    //
    // The package's own `gate.enabled` below stays OFF: it checks a DIFFERENT
    // ability (`viewApiDock`, no trailing s) that this kit does not define, and
    // the package registers a deny-all default for it — turning it on would lock
    // the panel for everyone, `developer` included.
    'middleware' => ['web', 'auth', CheckApiDocsAccess::class],
    'include_generation_timestamp' => false, // Stamp the generation time into the document. Off by default: it makes every regeneration a diff.

    'gate' => [
        // An authorization check layered on top of the 'middleware' stack above.
        //
        // Off by default, so no existing installation changes behaviour. When true,
        // EVERY API Dock route -- the panel, the spec, the try-it proxy and the
        // credential-profile endpoints -- additionally requires the 'viewApiDock' Gate
        // ability to pass. A denied request gets the same 404 this package returns when
        // 'enabled' is false, not a 403: a refusal must not confirm that the panel is
        // served here.
        //
        // This fails CLOSED. Turning it on without defining the ability denies everyone,
        // yourself included, rather than quietly allowing everyone: the package registers
        // a deny-all 'viewApiDock' default when the application has not defined one, so a
        // misspelled ability name locks the panel instead of leaving it open while you
        // believe it is gated. Define the real one in a service provider:
        //
        //     Gate::define('viewApiDock', fn ($user) => $user->isAdmin());
        //
        // Normal Gate semantics apply on top of that: a guest is denied unless the
        // ability declares a nullable user parameter, and a 'Gate::before' callback that
        // returns true still wins, exactly as it does for every other ability in the app.
        'enabled' => false,
    ],

    'ui' => [
        'locale' => null, // Locale used by the documentation UI; null derives it from the host application.
        'theme' => 'light', // Default documentation UI theme before a stored preference is applied.
    ],

    'ai' => [
        'export_path' => storage_path('api-dock'), // Default directory for generated AI artifacts.
        'include_examples' => true, // Include OpenAPI examples in AI-oriented exports.
        'mcp_opt_in' => false, // When true, only operations carrying an AiTool attribute are exported as MCP tools.
    ],

    'snapshot' => [
        'path' => storage_path('api-dock/openapi.json'), // Stored OpenAPI snapshot used by diff commands.
    ],

    'try_it' => [
        // On by default so the panel is usable out of the box: with `allowed_hosts` empty
        // below, the proxy reaches THIS application's own host and nothing else. Note that
        // it inherits `middleware` above — leave that at a bare `['web']` and the proxy is
        // anonymous like the panel, so gate both before you deploy.
        'enabled' => true,
        // This application's own host — and any subdomain of it — is always reachable and needs no entry here; trying your own documented API is the point of the panel.
        // This list is only for FOREIGN hosts. Empty denies every one of them. A bare name ('api.example.com') is an exact match; a leading dot ('.example.com') covers that site and its subdomains, the way a cookie domain does.
        'allowed_hosts' => [],
        // THIS application's own additional domains — nothing else. The host of `app.url` is already treated as self and needs no entry; add a name here only when this app is also served under it (a second domain, a reverse proxy in front of a different `APP_URL`, a tenant domain of yours).
        // A subdomain of any entry counts as self too. An entry skips the allowlist above, the internal-host list AND the address-class check that follows DNS, so every line is a deliberate decision to trust that name: an internal service name here reopens SSRF on purpose. Bare hostnames only — a leading-dot form is ignored, and so is an address literal in any spelling ('127.0.0.1', '127.1', '2130706433', '0x7f000001'), since the last label of a real name starts with a letter.
        // A 'name:port' entry ('ikinci-alan.test:8080') also reaches that name on that port without listing it in 'allowed_ports' below; the port applies to that entry and its subdomains only. An out-of-range port drops the whole entry rather than being read as "no port".
        'self_hosts' => [],
        'allowed_ports' => [80, 443], // Ports the proxy may reach on a FOREIGN host. An allowlisted host usually co-locates internal services, so an open port range would turn one allowed name into a port scanner. Empty or malformed falls back to these two. This application's own port is added automatically when 'app.url' (or a 'self_hosts' entry) carries one, so 'http://localhost:8000' works without widening this list for every allowlisted host.
        'timeout' => 10, // Maximum outbound request duration in seconds.
        'connect_timeout' => 5, // Seconds allowed for the connection itself, so an unreachable host fails fast instead of holding a worker.
        'max_request_bytes' => 262144, // 256 KB ceiling on the body the panel may send out. The throttle bounds how often a request leaves, not how much this server buffers and ships per request.
        'max_response_bytes' => 262144, // 256 KB ceiling on the proxied body; anything beyond it is truncated and flagged rather than buffered.
        'throttle' => '30,1', // Rate limit (requests,minutes) on the proxy route, since every call is an outbound request from your server.
        'allowed_methods' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], // Verbs the panel may request; narrow this to widen nothing.
        // By default credential profiles live in the session, so they last exactly as long as the reader's login: no expiry to configure, and logging out takes them with it.
        'max_profiles' => 10, // Credential profiles kept per session; the oldest is dropped past this. Every request unserializes the whole session payload, so an uncapped list would tax every page load.
        'profile_persistence' => [
            // Off by default: preserves the existing session-scoped guarantee for every
            // consumer of this package. A consuming app opts in explicitly.
            //
            // Turning this ON is a deliberate security trade-off. The profiles move to the
            // cache, keyed by the authenticated user id, and logging out no longer deletes
            // them: a stored credential then survives until it is deleted from the panel or
            // its TTL expires. The credential itself is still encrypted with the app
            // encrypter before it reaches the cache driver, so a cache dump yields
            // ciphertext — but anyone who can log back in as that user gets the profile back.
            // Leave this off unless a shared workstation is not part of your threat model.
            //
            // A request with no authenticated user (a guest whose only identity IS the
            // session) always falls back to session storage even with this on: there is no
            // stable identity to key persistent storage on, and keying it on anything weaker
            // would hand one visitor's credential to the next.
            'enabled' => false,
            // Minutes. Default 30 days — long enough that a rarely-rotated API token is
            // not re-entered every login, short enough that a stale credential does not
            // live forever. Requires a cache store that is actually shared and persistent
            // ('redis', 'memcached', 'database'); with 'array' the profiles die with the
            // process and with a per-node 'file' store they follow whichever node served
            // the write.
            'ttl_minutes' => 60 * 24 * 30,
            // The key is namespaced by the default auth guard, so two guards (or two
            // providers) never collide on a shared numeric id. That is NOT enough for a
            // tenant-per-database app whose per-tenant `users` table restarts ids at 1
            // while every tenant shares one cache store — set a closure here that
            // returns the current tenant's id (e.g. `fn () => tenant('id')`) to also
            // namespace by tenant. Leave null for a single-tenant app; the guard+id key
            // is already unique there.
            'key_namespace' => null,
        ],
    ],
];
