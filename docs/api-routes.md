# API Routes Admin Module

The ApiRoutes module exposes the application's API and service route surface inside the admin panel. It is useful for teams integrating with the API, developers validating route wiring, and admins who need quick visibility into the available endpoints.

## What It Does

- lists API endpoints from inside the panel
- shows service routes in a separate section
- displays HTTP method, URI, route name, action, and middleware
- lets admins regenerate the api-dock OpenAPI document
- provides a shortcut to the `/api-dock` documentation panel
- pushes the spec to Postman as a fresh collection
- pushes the spec to Apidog and overwrites the target project

## Routes

The module uses these web routes:

| Method | Path | Route name | Purpose |
| --- | --- | --- | --- |
| `GET` | `/api-routes` | `api-routes.index` | Shows the API and service route lists |
| `POST` | `/api-routes/regenerate-docs` | `api-routes.regenerateDocs` | Regenerates API documentation |
| `POST` | `/api-routes/postman-sync` | `api-routes.syncPostman` | Pushes the current OpenAPI spec to Postman |
| `POST` | `/api-routes/apidog-sync` | `api-routes.syncApidog` | Pushes the current OpenAPI spec to Apidog |

See [routes/web/developer-route.php](../stubs/routes/web/developer-route.php) for the definitions.

## Screen Behavior

`resources/js/pages/Admin/ApiRoutes/Index.vue` renders two main sections:

- **API Endpoints**: endpoints exposed under `/api/v1`
- **Service Endpoints**: helper routes used by the panel itself

Each entry shows:

- HTTP method
- URI
- route name
- controller action
- middleware list

Page actions:

- **Regenerate Docs**: rebuilds the api-dock OpenAPI document and writes it to an `admin/` subdirectory of `config('api-dock.ai.export_path')` — `storage/api-dock/admin/openapi.json` by default. It deliberately does not write to the export root, because that path is also the default `api-dock.snapshot.path` that `api-dock:diff` / `api-dock:sync --check` compare against; overwriting it from the panel would silently rewrite the CI baseline.
- **Open API Docs**: opens the api-dock panel in a new tab. The URL is resolved server-side from the `api-dock.docs` named route, so a custom `api-dock.route_prefix` is honoured; when api-dock is absent or disabled the button is not rendered.

## Backend Structure

- Controller: `app/Http/Controllers/Admin/ApiRouteController.php` (scaffolded into your app)
- Query: `Lvntr\StarterKit\Domain\ApiRoute\Queries\ApiRouteListQuery` (vendor-resident, `src/Domain/ApiRoute/`)
- Action: `Lvntr\StarterKit\Domain\ApiRoute\Actions\RegenerateApiDocsAction` (vendor-resident, `src/Domain/ApiRoute/`)

The ApiRoute runtime layer runs from the package; `App\Domain\ApiRoute\...` imports keep working through `class_alias`.

The controller renders the list view through Inertia and returns the regenerate result through the standard `ApiResponse` envelope.

## Access & Permissions

This screen runs inside the authenticated admin route group and passes through `check.permission`. Because the route name is `api-routes.index`, access follows the project's permission resolution rules.

The project also defines related permission entries such as `api-docs.read`. For the broader authorization model, see [roles-permissions.md](./roles-permissions.md).

## API Client Sync

The admin page ships two extra toolbar buttons next to **Regenerate Docs**:

- **Sync to Postman**: runs `SyncPostmanAction`, which builds the current OpenAPI document through api-dock's `DocumentGenerator` and uploads it to Postman's `POST /import/openapi` endpoint with `folderStrategy=Tags`. Each sync imports a fresh collection, persists the new UID to settings, then best-effort deletes the previous collection — an `import-first, delete-after` sequence so a transient Postman outage cannot leave the workspace without a working collection.
- **Sync to Apidog**: runs `SyncApidogAction`, which uploads the same document to Apidog's `POST /v1/projects/{id}/import-openapi` endpoint as inline JSON with `OVERWRITE_EXISTING` mode.

Both buttons share a loading spinner and a result toast. If the matching credentials are missing, the button is disabled and a hint redirects to **Settings → API Clients**, where the `postman` and `apidog` settings groups live. The secret fields (`postman.api_key`, `apidog.access_token`) are encrypted at rest via the `sensitive_keys` list in [config/settings.php](../stubs/config/settings.php).

The Actions share a helper, `Lvntr\StarterKit\Domain\ApiRoute\Support\OpenApiExporter` (vendor-resident, `src/Domain/ApiRoute/`), which resolves api-dock's `DocumentGenerator` from the container — the same entry point the `/api-dock` panel and every `api-dock:*` console command use, so what gets pushed to Postman/Apidog is byte-for-byte what the panel renders. The document is handed to the target client **unchanged**; content-type rewriting is deliberately avoided so the pushed collection mirrors the real server contract.

The same flows are exposed on the CLI for CI use:

    php artisan postman:sync
    php artisan apidog:sync

Both commands reuse the Action classes, so credential and permission rules are identical to the UI path.

## When To Use It

- when you need a quick panel-level overview of the current API surface
- when validating route wiring and middleware before integration work
- when you want to regenerate API documentation after backend changes
- when tracing which endpoint maps to which controller action during support or debugging
