<?php

use App\Domain\Shared\Services\DefinitionService;
use App\Http\Responses\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/*
|--------------------------------------------------------------------------
| User-Published Override Hook
|--------------------------------------------------------------------------
|
| When loaded from `vendor/lvntr/laravel-starter-kit/src/sk-helpers.php`,
| the dirname() walk lands at the consumer application's base path. If the
| user has published a customised copy via `php artisan sk:publish
| --tag=helpers`, route through it so their definitions win. The
| `require_once` is idempotent, so this stays safe in every load order.
|
| When this same file is loaded as the user's published copy, the realpath
| guard short-circuits the recursion and the function declarations below
| run normally.
|
*/

$skPublishedHelpers = dirname(__DIR__, 4).'/app/Helpers/sk-helpers.php';
if (is_file($skPublishedHelpers) && realpath($skPublishedHelpers) !== realpath(__FILE__)) {
    require_once $skPublishedHelpers;
    unset($skPublishedHelpers);

    return;
}
unset($skPublishedHelpers);

if (! function_exists('to_api')) {
    /**
     * Wrap data with ApiResponse and return.
     *
     * Automatically detects paginators (including simplePaginate / cursor) and
     * extracts pagination meta. Returns an ApiResponse on every branch except
     * 204, where a raw JsonResponse with an empty body is emitted — that keeps
     * controllers typed `: JsonResponse` working while allowing the fluent
     * chain on every other status.
     *
     * Usage:
     *   return to_api($user);
     *   return to_api($user, 'User retrieved.');
     *   return to_api(User::paginate(15));
     *   return to_api($user, 'Created.', 201);
     *   return to_api(null, 'Error', 400);  // success: false
     *   return to_api(status: 204);         // empty 204 body
     */
    function to_api(mixed $data = null, string $message = 'Operation successful.', int $status = 200): ApiResponse|JsonResponse
    {
        // Error responses (4xx, 5xx) — data is ignored on purpose.
        if ($status >= 400) {
            return ApiResponse::error($message, $status);
        }

        // 204 No Content — for delete/update operations with no response body.
        if ($status === 204) {
            return ApiResponse::noContent();
        }

        // Auto-detect paginators before the 201/202 branches so a paginator
        // dispatched with a non-200 status (e.g. 201 batch-create) still
        // receives pagination meta instead of being serialised as a raw object.
        if ($data instanceof LengthAwarePaginator || $data instanceof CursorPaginator || $data instanceof Paginator) {
            $response = ApiResponse::paginated($data, $message);
        } elseif ($data instanceof AnonymousResourceCollection && (
            $data->resource instanceof LengthAwarePaginator
            || $data->resource instanceof CursorPaginator
            || $data->resource instanceof Paginator
        )) {
            $response = ApiResponse::paginatedCollection($data, $message);
        } elseif ($status === 201) {
            return ApiResponse::created($data, $message);
        } else {
            // 200 OK (default) and any other 2xx we treat as success + status override.
            $response = ApiResponse::success($data, $message);
        }

        if ($status !== 200) {
            $response->status($status);
        }

        return $response;
    }
}

if (! function_exists('definition')) {
    /**
     * Get a single definition record by key and value.
     *
     * Usage:
     *   definition('transportPlanStatus', 'active')  → Definition object
     *   definition('transportPlanStatus', 'active')?->label  → 'Aktif'
     */
    function definition(string $key, mixed $value): ?object
    {
        $service = app(DefinitionService::class);
        $items = $service->get($key);

        foreach ($items as $item) {
            if ((string) ($item['value'] ?? '') === (string) $value) {
                return (object) $item;
            }
        }

        return null;
    }
}

if (! function_exists('definitionLabel')) {
    /**
     * Get the label for a definition key+value pair.
     *
     * Usage:
     *   definitionLabel('identityType', 1)  → 'Türkiye'
     */
    function definitionLabel(string $key, mixed $value): ?string
    {
        return definition($key, $value)?->label;
    }
}

if (! function_exists('format_date')) {
    /**
     * Format a date/datetime to the application's display timezone.
     *
     * Usage:
     *   format_date($model->created_at)          → '14-03-2026 08:36'
     *   format_date($model->created_at, 'date')  → '14-03-2026'
     *   format_date($model->created_at, 'time')  → '08:36'
     */
    function format_date(Carbon|string|null $value, string $type = 'datetime'): ?string
    {
        if ($value === null) {
            return null;
        }

        $carbon = $value instanceof Carbon ? $value : Carbon::parse($value);
        $carbon = $carbon->setTimezone(config('app.display_timezone'));

        return match ($type) {
            'date' => $carbon->format('d-m-Y'),
            'time' => $carbon->format('H:i'),
            default => $carbon->format('d-m-Y H:i'),
        };
    }
}
