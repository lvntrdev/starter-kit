---
name: lvntr-kit-domain
description: "Enforces backend/DDD patterns for Lvntr Starter Kit apps. Triggers on: app/Domain/, app/Http/Controllers/Admin/, app/Http/Controllers/Api/, FormRequest, Resource, Action, DTO, Query, Events, Listeners, routes/web/*-route.php, routes/api/*-route.php, to_api, ApiResponse, ApiException, BaseAction, BaseDTO, ActionPipeline, DatatableQueryBuilder, sk:eject. Also triggers on Turkish: yeni domain, domain ekle, action ekle, controller yaz, api endpoint ekle. Use when working on the backend/DDD layer (controllers, actions, DTOs, API responses, routes) of a Lvntr Starter Kit app."
---

# lvntr-kit-domain — Backend / DDD Reference

Pairs with `lvntr-starter-kit` (core: hard rules, project shape, commands,
permissions, i18n, built-in modules). The same entity's frontend
(form/table/Vue) → `lvntr-kit-frontend`.

---

## Iron Laws (backend)

These five rules apply on every backend task. Violating any one breaks either upgradeability or API contract.

1. **Never edit `vendor/lvntr/laravel-starter-kit/`.** Patches there vanish on `composer update`. Override via `app/` (a local copy always wins), publish with `sk:publish`, or take ownership with `sk:eject`.
3. **Never use `response()->json()` in API controllers.** Use `to_api(...)` or `ApiResponse::*`; throw `ApiException::*` for errors. The exception handler installed by the kit enforces the standard envelope.
6. **Never put business logic in controllers.** Push it into `app/Domain/{Entity}/Actions/`. Controllers stay 5-line thin.
7. **Run `vendor/bin/pint --dirty --format agent`** before finishing any PHP change. The pre-commit hook rejects otherwise. Do not `--amend`; create a new commit after the fix.
8. **Never edit a committed migration.** Add a new one. Two-step destructive changes (add first, drop in a follow-up) keep production safe.

*(Numbering follows the canonical hard-rule list in `lvntr-starter-kit` §1.)*

---

## Triggers

This skill applies when a task touches any of:

- `app/Domain/` — any sub-path
- `app/Http/Controllers/Admin/` or `app/Http/Controllers/Api/`
- `app/Http/Requests/`, `app/Http/Resources/`
- `routes/web/*-route.php`, `routes/api/*-route.php`
- Symbols: `to_api`, `ApiResponse`, `ApiException`, `BaseAction`, `BaseDTO`, `ActionPipeline`, `DatatableQueryBuilder`
- Commands: `make:sk-domain`, `remove:sk-domain`, `sk:seed-permissions`, `sk:eject`

---

## Vendor-first note (v13.6.0+)

On a fresh install only `User` and `Role` are app-owned domains (auto-ejected
by `sk:install`). Other kit domains (`Setting`, `ActivityLog`, `Logs`,
`Session`, `Media`, `ApiClient`, `ApiRoute`, `FileManager`, `Shared` base
classes) **run from the vendor package**; their `App\Domain\…` names resolve
via `class_alias`, and an app copy — when you create one via `sk:eject` —
always wins. Extend or configure kit modules; eject only when you truly need
project-owned code (trade-off: no more upstream updates for that domain).

`ApiResponse`, `ApiException`, and the `sk-helpers` (`to_api()`,
`format_date()`, …) also run from vendor. `App\Http\Responses\ApiResponse` is
a vendor alias — do not subclass it; `app/Http/Responses/DatatableQueryBuilder.php`
is a thin app shim you may extend.

---

## End-to-End Recipe — Backend Steps

The fast path is always `php artisan make:sk-domain Entity` (add
`--with=policy,factory,seeder,test` or `--relations="belongsTo:User,…"` for
the opt-in extras). For manual wiring or existing models, follow these steps
in order. **Steps 14-16 (frontend) are in `lvntr-kit-frontend`.**

### Step 1 — Model + migration + factory

```bash
php artisan make:model Product -mf --no-interaction
```

### Step 2 — Domain folders

```
app/Domain/Product/{Actions,DTOs,Queries,Events,Listeners}
```

### Step 3 — DTO

`app/Domain/Product/DTOs/ProductDTO.php`

- `readonly class ProductDTO extends BaseDTO`
- Implement `fromArray(array $data): static` and `toArray(): array`
- Properties in `camelCase`; array keys in `snake_case`
- Optional fields default to `null`; omit from `toArray()` to skip the DB column (e.g. "don't change password" flows)

### Step 4 — Actions

`app/Domain/Product/Actions/{Create,Update,Delete}ProductAction.php`

- Each extends `BaseAction`
- Single public `execute()` method
- Inject deps via constructor (PHP 8 promoted properties)
- HTTP-context free — pass `?string $performedById = null` when needed
- Dispatch domain events on success: `ProductCreated::dispatch($product, $performedById)`
- Throw `\LogicException` for guarded failures; the kit's handler maps it to a 422 on API routes, and Admin controllers catch and flash it

### Step 5 — Datatable query

`app/Domain/Product/Queries/ProductDatatableQuery.php`

- Single `response(): ApiResponse` method
- Must go through `DatatableQueryBuilder` — this is the only way to produce the shape `<SkDatatable>` expects:

```php
return DatatableQueryBuilder::for(Product::query())
    ->searchable(['name', 'sku'])
    ->sortable(['name', 'price', 'created_at'])
    ->filterable(['status'])
    ->with(['category'])
    ->resource(ProductResource::class)
    ->response();
```

### Step 6 — Events + Listeners (optional)

- `app/Domain/Product/Events/{Created,Updated,Deleted}.php` — `Dispatchable + SerializesModels`
- `app/Domain/Product/Listeners/Log{…}.php` — typically `implements ShouldQueue`
- **Register in `app/Providers/DomainServiceProvider::boot()`** — listeners are NOT auto-discovered. Forgetting this is the #1 mistake. (Vendor-resident kit domains wire their own listeners in the package provider — that part is not your concern.)

### Step 7 — FormRequests

`app/Http/Requests/Admin/Product/{Store,Update}ProductRequest.php`

- `authorize()` returns `true` — resource permission middleware handles auth
- `rules()` defines validation; field labels come from `lang/{locale}/validation.php` under `attributes.{snake_case_key}` — never via `messages()`
- Override `prepareForValidation()` only when reshaping input

### Step 8 — API Resource

`app/Http/Resources/Admin/Product/ProductResource.php`

- `/** @mixin Product */` docblock for static analysis
- `format_date($this->created_at)` for datetimes (respects `app.display_timezone`)
- `$this->whenLoaded('relation', fn () => …)` for conditional relations

### Step 9 — Controllers

- `app/Http/Controllers/Admin/ProductController.php` — Inertia + flash
- `app/Http/Controllers/Api/ProductController.php` — `ApiResponse` only, no Inertia
- Both stay thin: resolve FormRequest + Action via method injection, build `DTO::fromArray($request->validated())`, call `->execute(...)`, return `back()->with('success', __(…))` (Admin) or `to_api(...)` (Api)

### Step 10 — Route files

`routes/web/product-route.php` and `routes/api/product-route.php`

- Use `Route::prefix(...)->name(...)->controller(...)->group(...)` for short lines
- Conventional names: `dtApi` for the datatable endpoint, `data` for JSON read
- The kit's loader auto-discovers `*-route.php` and wraps them in `auth + verified + check.permission` middleware — do not add these manually

### Step 11 — Permissions

Append the resource to `config/permission-resources.php`:

```php
'products' => [
    'label' => 'sk-product.product',
    'abilities' => ['read', 'create', 'update', 'delete'],
],
```

Then re-seed:

```bash
php artisan sk:seed-permissions --fresh
```

### Step 12 — Translations

- Field labels → `lang/{en,tr}/validation.php` under `attributes`
- Entity strings → `lang/{en,tr}/sk-{entity}.php`

### Step 13 — Wayfinder regen

```bash
php artisan wayfinder:generate
```

---

## Domain Layer Flow

```
Controller → FormRequest (validate) → DTO (BaseDTO::fromArray) → Action (BaseAction::execute)
                                                                ↓
                                                           Event dispatched
                                                                ↓
                                                           Listeners react
                                              (logging, mail, broadcast — usually queued)
```

- **Controller:** 5 lines max. No business logic. No `$request->validate()`.
- **FormRequest:** `authorize()`, `rules()`, optional `prepareForValidation()`.
- **DTO:** `readonly`, immutable, self-mapping, no validation.
- **Action:** one `execute()`, inject deps, dispatch events, throw `\LogicException` for domain guards.
- **Event + Listener:** wired in `DomainServiceProvider::boot()` — NOT auto-discovered.
- **ActionPipeline:** only for multi-step transactional workflows (auto-wraps in `DB::transaction`; `withoutTransaction()` to opt out). Single-action flows don't need it.

Reference domain: `app/Domain/User/` is the canonical kit example for all patterns. Thin controller template: `app/Http/Controllers/Admin/UserController.php`.

---

## API Responses — Standard Envelope

Every JSON response flows through one of two helpers. Errors flow through `ApiException::*`; the registered handler maps them onto the same envelope shape.

### Success

```php
// helpers (preferred for simple cases)
return to_api($product);                                    // 200 OK
return to_api($product, __('sk-message.created'), 201);     // 201 Created
return to_api(['avatar_url' => $url], __('sk-message.avatar_uploaded'));
return to_api(Product::paginate(15));                       // auto-paginated meta
return to_api(null, 'Done.', 204);                          // no content

// fluent (when you need .meta(), .header(), .errors())
return ApiResponse::success($data, 'OK')->meta(['extra' => 1])->header('X-Foo', 'bar');
return ApiResponse::created($product, __('sk-message.created'));
return ApiResponse::paginated($paginator);
```

### Errors

```php
throw ApiException::notFound('Product not found.');
throw ApiException::forbidden();
throw ApiException::badRequest('Invalid filter combination.');
throw ApiException::conflict('SKU already exists.');
throw ApiException::unauthorized();
throw ApiException::unprocessable($errors);   // 422 with field errors
throw ApiException::serverError('Upstream timeout.');
```

The handler auto-maps Laravel's built-ins: `ModelNotFoundException → 404`, `ValidationException → 422`, `AuthenticationException → 401`, `ThrottleRequestsException → 429`, `LogicException → 422`.

### Envelope shape

```json
{
  "success": true,
  "status": 200,
  "message": "Operation successful.",
  "data": { "…": "…" },
  "errors": null,
  "meta": { "…": "…" },
  "trace_id": "…",
  "debug": { "…": "…" }
}
```

`errors` only on failure. `meta` only when set. `debug` only with `APP_DEBUG=true`.

**API messages and PHP docblocks must be in English.** Turkish belongs in UI copy, commit bodies, and internal docs only.

---

## Backend Pitfalls

1. **Forgetting to register a Listener** in `DomainServiceProvider::boot()` — it won't fire, no error. Always wire `Event::listen($event, $listener)`.
2. **Returning `response()->json(...)`** from an Api controller — bypasses the standard envelope. Use `to_api()` / `ApiResponse::*`.
3. **Custom datatable shape** — `<SkDatatable>` expects exactly `DataTableResponse<T>`. Always go through `DatatableQueryBuilder`.
4. **Mocking the database in tests** — use `RefreshDatabase` + Passport's `actingAs($user)`. Kit ships factories for User and core entities; don't mock DB calls.
5. **Editing `vendor/lvntr/laravel-starter-kit/`** — every change is lost on `composer update`. Use `sk:publish` / `sk:eject` to extract what you need.
6. **Skipping `vendor/bin/pint --dirty --format agent`** — the pre-commit hook rejects the commit. Don't `--amend`; create a new commit after the fix.
7. **Subclassing `ApiResponse`** — it's a vendor alias with covariance constraints; use the fluent API or `to_api()` instead.
8. **Assuming an authenticated request means an enabled account.** `EnsureUserIsActive` (alias `sk.active`, appended to the `web` and `api` groups) terminates a session whose `status` is on `starter-kit.security.active_status_denied` (default `['inactive', 'banned']`) — 403 in the `ApiResponse` envelope for JSON, logout + redirect to `login` for web. It is deliberately fail-open on every ambiguous input (no `status` attribute, a non-string value, an unlisted status) and `security.enforce_active_status = false` disables it — and the matching `RevokeUserAccessAction` (Passport access + refresh tokens, unredeemed authorization/device codes, DB session rows) — outright.
9. **Widening a bulk-selection query past what the table showed.** `BulkFilterSnapshot::normalize()` fails closed: an active `filter[...]` key it cannot apply 422s (`sk-bulk.unknown_filters`) rather than being dropped, and `'true'`/`'false'` are coerced to booleans exactly as Spatie's `QueryBuilderRequest` does. Search predicates and date bounds must go through `DatatableQueryBuilder::applySearchWords()` / `::applyCalendarDateRange()` — the same helpers the table's own query uses — so the two paths cannot drift.
10. **Storing a locale wider than 35 characters in `definitions.lang`.** The column is narrowed to 35 so the `(key, value, lang)` unique index stays under InnoDB's 3072-byte key limit; `key` and `value` keep 255.

---

## Bottom Line

Thin controller. DTO in, Action out. Envelope always. Register your listeners. Never touch vendor — publish or eject. Run pint. New migration, never edit.
