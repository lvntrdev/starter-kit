<?php

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;

if (! function_exists('to_api')) {
    /**
     * Wrap data with ApiResponse and return.
     *
     * Automatically detects paginators and extracts pagination meta.
     * Prefers App\Http\Responses\ApiResponse when available, falls back to package class.
     *
     * Usage:
     *   return to_api($user);
     *   return to_api($user, 'User retrieved.');
     *   return to_api(User::paginate(15));
     *   return to_api($user, 'Created.', 201);
     *   return to_api(null, 'Error', 400);  // success: false
     */
    function to_api(mixed $data = null, string $message = 'Operation successful.', int $status = 200): mixed
    {
        $apiResponse = class_exists(\App\Http\Responses\ApiResponse::class)
            ? \App\Http\Responses\ApiResponse::class
            : \Lvntr\StarterKit\Http\Responses\ApiResponse::class;

        // Error responses (4xx, 5xx)
        if ($status >= 400) {
            return $apiResponse::error($message, $status);
        }

        // 201 Created
        if ($status === 201) {
            return $apiResponse::created($data, $message);
        }

        // 202 Accepted — job queued, not yet completed
        if ($status === 202) {
            return $apiResponse::success($data, $message ?: 'Operation queued.')->status(202);
        }

        // 204 No Content — for delete/update operations with no response body
        if ($status === 204) {
            return $apiResponse::noContent();
        }

        // Auto-detect paginators
        if ($data instanceof LengthAwarePaginator || $data instanceof CursorPaginator) {
            return $apiResponse::paginated($data, $message);
        }

        // 200 OK (default)
        return $apiResponse::success($data, $message);
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
