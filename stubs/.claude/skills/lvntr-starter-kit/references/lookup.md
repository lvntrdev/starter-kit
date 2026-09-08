# Where to look first

> Reference detail for the `lvntr-starter-kit` skill — read on demand.

If something is unclear, read in this order:

| Want to see | Look at |
|---|---|
| Action / DTO / Query patterns | `app/Domain/User/` (ejected on fresh installs — the reference domain) |
| Thin controller template | `app/Http/Controllers/Admin/UserController.php` |
| FormRequest pattern | `app/Http/Requests/Admin/User/StoreUserRequest.php` |
| Resource pattern | `app/Http/Resources/Admin/User/UserResource.php` |
| Event/Listener wiring (your domains) | `app/Providers/DomainServiceProvider.php` |
| Datatable query helper | `app/Http/Responses/DatatableQueryBuilder.php` (thin shim → vendor implementation) |
| ApiResponse / ApiException / `to_api()` | vendor: `Lvntr\StarterKit\Http\Responses\ApiResponse`, `src/sk-helpers.php` (the `App\` names are vendor aliases — read-only) |
| Route file template | `routes/web/user-route.php` |
| Permission resource shape | `config/permission-resources.php` |
| Builder source (after `sk:publish`) | `resources/js/components/Lvntr-Starter-Kit/{FormBuilder,DatatableBuilder,TabBuilder}/core/` |
| Document an API endpoint for a model / add an AI hint | `LvntR\ApiDock\Attributes\{AiHint,AiPitfall,AiChangelog,AiExample,AiTool,ApiFeature}` on the `App\Http\Controllers\Api\*` class/method — `docs/api-ai-metadata.md` |
| Composables (published local copies) | `resources/js/composables/` — otherwise they run from vendor (`sk:publish --tag=composables`) |
| Theme slots | `resources/css/theme/main/` (base) · `resources/css/theme/custom/` (override) |

If you need to peek inside the package itself, it's at
`vendor/lvntr/laravel-starter-kit/{src,stubs,config}` — read-only.

For external references (online docs, screenshots, full API tables):
**[starter-kit.lvntr.dev](https://starter-kit.lvntr.dev/)**.
