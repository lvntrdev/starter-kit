<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Fluent API Response Builder
 *
 * Usage:
 *   return ApiResponse::success($data);
 *   return ApiResponse::success($data, 'User retrieved.');
 *   return ApiResponse::created($user, 'Record created.');
 *   return ApiResponse::error('An error occurred.', 400);
 *   return ApiResponse::paginated(User::paginate());
 *   return ApiResponse::success($data)->meta(['extra' => 'info'])->header('X-Custom', 'value');
 */
class ApiResponse implements Responsable
{
    private bool $success;

    private int $status;

    private string $message;

    private mixed $data;

    private ?array $errors = null;

    private array $meta = [];

    private ?string $traceId = null;

    private ?array $debugInfo = null;

    private array $extraHeaders = [];

    private function __construct(bool $success, int $status, string $message, mixed $data = null)
    {
        $this->success = $success;
        $this->status = $status;
        $this->message = $message;
        $this->data = $data;
    }

    // ─── Factory Methods ────────────────────────────────────────────────

    /**
     * Success response.
     */
    public static function success(mixed $data = null, string $message = 'Operation successful.'): self
    {
        return new self(true, 200, $message, $data);
    }

    /**
     * Resource created response (201).
     */
    public static function created(mixed $data = null, string $message = 'Record created.'): self
    {
        return new self(true, 201, $message, $data);
    }

    /**
     * Error response.
     */
    public static function error(string $message = 'An error occurred.', int $status = 400): self
    {
        return new self(false, $status, $message);
    }

    /**
     * Paginated response for a Laravel API ResourceCollection.
     *
     * Use this when you want to transform paginated items through an
     * `JsonResource::collection($paginator)` before returning. It preserves
     * the resource transformation and exposes pagination metadata.
     */
    public static function paginatedCollection(ResourceCollection $collection, string $message = 'Operation successful.'): self
    {
        $paginator = $collection->resource;

        // Transform each resource item via its toArray() so the output
        // matches what `success()` would produce for non-paginated data.
        $items = $collection->collection->map(
            fn ($resource) => $resource->toArray(request())
        )->all();

        $meta = match (true) {
            $paginator instanceof LengthAwarePaginator => [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'path' => $paginator->path(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
            $paginator instanceof CursorPaginator => [
                'path' => $paginator->path(),
                'per_page' => $paginator->perPage(),
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'prev_cursor' => $paginator->previousCursor()?->encode(),
                'has_more' => $paginator->hasMorePages(),
            ],
            default => [],
        };

        $instance = new self(true, 200, $message, $items);
        $instance->meta = array_merge($instance->meta, $meta);

        return $instance;
    }

    /**
     * No content response (204).
     */
    public static function noContent(): JsonResponse
    {
        return new JsonResponse(null, 204);
    }

    /**
     * Paginated response — supports LengthAwarePaginator or CursorPaginator.
     */
    public static function paginated(LengthAwarePaginator|CursorPaginator $paginator, string $message = 'Operation successful.'): self
    {
        $meta = match (true) {
            $paginator instanceof LengthAwarePaginator => [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'path' => $paginator->path(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
            $paginator instanceof CursorPaginator => [
                'path' => $paginator->path(),
                'per_page' => $paginator->perPage(),
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'prev_cursor' => $paginator->previousCursor()?->encode(),
                'has_more' => $paginator->hasMorePages(),
            ],
        };

        $instance = new self(true, 200, $message, $paginator->items());
        $instance->meta = array_merge($instance->meta, $meta);

        return $instance;
    }

    // ─── Fluent Setters ─────────────────────────────────────────────────

    /**
     * Override the message.
     */
    public function message(string $message): static
    {
        $this->message = $message;

        return $this;
    }

    /**
     * Override the HTTP status code.
     */
    public function status(int $status): static
    {
        $this->status = $status;

        return $this;
    }

    /**
     * Append additional meta information.
     */
    public function meta(array $meta): static
    {
        $this->meta = array_merge($this->meta, $meta);

        return $this;
    }

    /**
     * Set the trace ID for request tracking.
     */
    public function traceId(string $traceId): static
    {
        $this->traceId = $traceId;

        return $this;
    }

    /**
     * Set debug information (only included when APP_DEBUG=true).
     */
    public function debug(array $debug): static
    {
        $this->debugInfo = $debug;

        return $this;
    }

    /**
     * Set validation errors.
     */
    public function errors(?array $errors): static
    {
        $this->errors = $errors;

        return $this;
    }

    /**
     * Add a single response header.
     */
    public function header(string $key, string $value): static
    {
        $this->extraHeaders[$key] = $value;

        return $this;
    }

    /**
     * Add multiple response headers.
     */
    public function headers(array $headers): static
    {
        $this->extraHeaders = array_merge($this->extraHeaders, $headers);

        return $this;
    }

    // ─── Output ─────────────────────────────────────────────────────────

    /**
     * Convert the response payload to an array.
     */
    public function toArray(): array
    {
        $payload = [
            'success' => $this->success,
            'status' => $this->status,
            'message' => $this->message,
            'data' => $this->data,
        ];

        if ($this->errors !== null) {
            $payload['errors'] = $this->errors;
        }

        if (! empty($this->meta)) {
            $payload['meta'] = $this->meta;
        }

        if ($this->traceId !== null) {
            $payload['trace_id'] = $this->traceId;
        }

        if ($this->debugInfo !== null) {
            $payload['debug'] = $this->debugInfo;
        }

        return $payload;
    }

    /**
     * Laravel Responsable — can be returned directly from controllers.
     */
    public function toResponse($request): JsonResponse
    {
        return response()
            ->json($this->toArray(), $this->status)
            ->withHeaders($this->extraHeaders);
    }
}
