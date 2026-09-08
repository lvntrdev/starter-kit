# AI Metadata for API Endpoints

`lvntr/api-dock` reads six PHP attributes off your `App\Http\Controllers\Api\*` classes and methods and emits them as vendor extensions on the generated OpenAPI document. They exist to make an endpoint self-describing to an AI agent (or a human skimming `/api-dock`) beyond what parameters and response shapes already say: why a field behaves the way it does, what trips people up, a runnable example, whether the operation is exposed as an MCP tool, and a human-written history of behavior changes.

None of this is required. An undecorated controller documents fine through Scramble alone; add attributes only where the base OpenAPI output leaves something unsaid.

## The Six Attributes

Source: `vendor/lvntr/api-dock/src/Attributes/`.

| Attribute | Target | Repeatable | Constructor |
| --- | --- | --- | --- |
| `AiHint` | class or method | no | `string $hint` |
| `AiPitfall` | class or method | **yes** | `string $text, int $order = 0` |
| `AiChangelog` | class or method | **yes** | `string $date, string $summary, bool $breaking = false` |
| `AiExample` | class or method | **yes** | `string $name, array $request = [], array $response = []` |
| `AiTool` | class or method | no | `bool $enabled = true, ?string $name = null, ?string $description = null` |
| `ApiFeature` | class or method | no | `?string $auth = null, ?array $scopes = null, ?int $rateLimit = null, ?string $rateLimitPer = null, ?bool $deprecated = null, ?string $stability = null` |

`AiHint`, `AiTool`, and `ApiFeature` are single-value: putting a second one on the same class or method just leaves the extras unread (only the first attribute instance is picked up). `AiPitfall`, `AiChangelog`, and `AiExample` are declared `IS_REPEATABLE` and are meant to be stacked.

## Merge Order

For every operation, `AiMetadataOperationExtension` and `FeatureOperationExtension` read **class-level attributes first, then method-level attributes**, and combine them:

- **`AiHint`**: method wins outright if present, otherwise the class hint is used. There is no merge — one hint reaches the document.
- **`AiPitfall`**: class pitfalls and method pitfalls are concatenated, then stably sorted by `order` (ties keep their original declaration position — class before method).
- **`AiChangelog`**: class and method entries are concatenated, then sorted **newest date first**; entries with an unparsable `date` sort last, ties keep declaration order.
- **`AiExample`**: class examples, then method examples, in that order — no sorting or dedup.
- **`AiTool`**: method wins outright if present, otherwise the class attribute is used (same single-value rule as `AiHint`).
- **`ApiFeature`**: this one *does* merge field-by-field rather than picking one instance. Every non-`null` field on a class-level `ApiFeature` is applied, then every non-`null` field on a method-level one is applied on top — so a method attribute overrides only the fields it sets and **a `null` field leaves the earlier value untouched**. `auth`, `rateLimit`/`rateLimitPer`, `deprecated`, and `stability` are also seeded from route middleware (`auth`, `can`/`ability`/`permission`/`scope*`, `throttle`) and the `#[Deprecated]` attribute before any `ApiFeature` override is applied.

## Emitted Vendor Extensions

Each attribute value lands in the generated OpenAPI operation under an `x-` prefixed key:

| Extension key | Source attribute(s) |
| --- | --- |
| `x-ai-hint` | `AiHint` |
| `x-ai-pitfalls` | `AiPitfall` (sorted array) |
| `x-ai-examples` | `AiExample` (array) |
| `x-ai-tool` | `AiTool` (`{enabled, name, description}`) |
| `x-api-dock-changelog` | `AiChangelog` (sorted array) |
| `x-api-dock-features` | `ApiFeature` + inferred middleware (`{auth, scopes, rate_limit, deprecated, stability}`) |

## Worked Example

Annotating `store()` on the kit's own `App\Http\Controllers\Api\UserController` (`stubs/app/Http/Controllers/Api/UserController.php`), which already returns through the `ApiResponse` envelope via `to_api()`:

```php
use LvntR\ApiDock\Attributes\AiChangelog;
use LvntR\ApiDock\Attributes\AiExample;
use LvntR\ApiDock\Attributes\AiHint;
use LvntR\ApiDock\Attributes\AiPitfall;
use LvntR\ApiDock\Attributes\ApiFeature;

#[AiHint('Creates an admin-managed user; the caller must already hold users.create.')]
#[ApiFeature(scopes: ['users.create'])]
class UserController extends Controller
{
    #[AiPitfall('email must be unique across active AND soft-deleted users — a re-invite reuses the row instead of erroring.', order: 1)]
    #[AiPitfall('roles is optional on create; an omitted value leaves the user with no role, not a default one.', order: 2)]
    #[AiExample(
        name: 'Create a support agent',
        request: ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com', 'roles' => ['support']],
        response: ['success' => true, 'status' => 201, 'message' => 'User created successfully.'],
    )]
    #[AiChangelog(date: '2026-08-01', summary: 'roles became optional; previously required.', breaking: true)]
    public function store(StoreUserRequest $request, CreateUserAction $action): ApiResponse
    {
        // ...
    }
}
```

The class-level `AiHint` and `ApiFeature` apply to every method on the controller (`index`, `show`, `update`, `destroy` included) unless a method overrides them; the pitfalls, example, and changelog above are scoped to `store()` alone.

## Export Surface

`php artisan api-dock:export` writes AI-oriented artifacts built from the same annotated OpenAPI document:

- **`--llms`** → `llms.txt`, a plain-text digest of the API meant to be pasted into an LLM context window or served at `/llms.txt`.
- **`--mcp`** → `mcp-tools.json`, MCP (Model Context Protocol) tool definitions. With `api-dock.ai.mcp_opt_in` set to `true` in `config/api-dock.php`, only operations carrying an `AiTool` attribute are exported; left `false` (the default), every operation is eligible.
- **`--openapi`** → the plain generated `openapi.json`, extensions included.

Artifacts are written to `config('api-dock.ai.export_path')` (default `storage_path('api-dock')`).

Separately, `php artisan api-dock:sync` regenerates the OpenAPI document, diffs it against the stored snapshot at `config('api-dock.snapshot.path')`, reports `breaking` / `additive` / `cosmetic` changes (`api-dock:diff --json` for the machine-readable form), and updates the snapshot.

**`AiChangelog` and the snapshot diff are deliberately two different things.** `AiChangelog` is a hand-written attribute aimed at a human integrator reading the docs UI or `llms.txt` — it says whatever you write in `summary`, on whatever schedule you remember to write it. The snapshot diff is machine-generated from comparing two OpenAPI documents field-by-field — it catches every schema drift whether or not anyone wrote it down. Use the diff to find out *that* something changed; use `AiChangelog` to say *why* it changed and what to do about it. Neither substitutes for the other.

## Security

The try-it proxy inside the `/api-dock` panel is **on by default** (`config('api-dock.try_it.enabled')`), but `try_it.allowed_hosts` and `try_it.self_hosts` both ship **empty** — with them empty the proxy can only reach this application's own host, not an arbitrary foreign one (see `stubs/config/api-dock.php` for the full SSRF-surface rationale before adding an entry).

The whole `/api-dock` surface — panel, spec, try-it proxy, and credential-profile endpoints — sits behind `['web', 'auth', CheckApiDocsAccess::class]` (`config('api-dock.middleware')`), which denies unless `Gate::allows('viewApiDocs')`. The kit wires that gate to the seeded `api-docs.read` permission ability (`config/permission-resources.php`), granted to the `developer` role by default, and fails **closed**: an unseeded ability denies rather than allowing through.
