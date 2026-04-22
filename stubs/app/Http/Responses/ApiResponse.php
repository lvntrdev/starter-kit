<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * Fluent API Response Builder
 *
 * Usage:
 *   return ApiResponse::success($data);
 *   return ApiResponse::success($data, 'User retrieved.');
 *   return ApiResponse::created($user, 'Record created.');
 *   return ApiResponse::error('An error occurred.', 400);
 *   return ApiResponse::paginated(User::paginate());
 *   return ApiResponse::paginatedCollection(UserResource::collection(User::paginate()));
 *   return ApiResponse::success($data)->meta(['extra' => 'info'])->header('X-Custom', 'value');
 *
 * @template TData
 */
final class ApiResponse implements Responsable
{
    private bool $success;

    private int $status;

    private string $message;

    /** @var TData */
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
     * Success response (200).
     *
     * @template T
     *
     * @param  T  $data
     * @return self<T>
     */
    public static function success(mixed $data = null, string $message = 'Operation successful.'): self
    {
        return new self(true, 200, $message, $data);
    }

    /**
     * Resource created response (201).
     *
     * @template T
     *
     * @param  T  $data
     * @return self<T>
     */
    public static function created(mixed $data = null, string $message = 'Record created.'): self
    {
        return new self(true, 201, $message, $data);
    }

    /**
     * Error response (4xx/5xx).
     *
     * @return self<null>
     */
    public static function error(string $message = 'An error occurred.', int $status = 400): self
    {
        return new self(false, $status, $message);
    }

    /**
     * No content response (204).
     *
     * Returns a raw JsonResponse with an empty body (per RFC 7230). The
     * AssignTraceId middleware still attaches the correlation headers on the
     * way out, so returning a plain JsonResponse does not lose traceability.
     * Returning JsonResponse here keeps existing controllers typed
     * `: JsonResponse` working without a signature change.
     */
    public static function noContent(): JsonResponse
    {
        return new JsonResponse('', 204);
    }

    /**
     * Paginated response — supports LengthAwarePaginator, CursorPaginator,
     * or simple Paginator (from `simplePaginate()`).
     *
     * @return self<array<int, mixed>>
     */
    public static function paginated(LengthAwarePaginator|CursorPaginator|Paginator $paginator, string $message = 'Operation successful.'): self
    {
        $instance = new self(true, 200, $message, $paginator->items());
        $instance->meta = array_merge($instance->meta, self::paginationMeta($paginator));

        return $instance;
    }

    /**
     * Paginated response for a Laravel API ResourceCollection.
     *
     * Preserves the resource transformation (e.g. hidden fields, computed attrs)
     * and exposes pagination metadata alongside the transformed items.
     *
     * @return self<array<int, array<string, mixed>>>
     */
    public static function paginatedCollection(ResourceCollection $collection, string $message = 'Operation successful.'): self
    {
        $paginator = $collection->resource;

        $items = $collection->collection->map(
            fn ($resource) => $resource->toArray(request())
        )->all();

        $instance = new self(true, 200, $message, $items);
        $instance->meta = array_merge($instance->meta, self::paginationMeta($paginator));

        return $instance;
    }

    /**
     * Extract pagination metadata from any supported paginator variant.
     */
    private static function paginationMeta(LengthAwarePaginator|CursorPaginator|Paginator $paginator): array
    {
        return match (true) {
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
            $paginator instanceof Paginator => [
                'current_page' => $paginator->currentPage(),
                'path' => $paginator->path(),
                'per_page' => $paginator->perPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
        };
    }

    // ─── Fluent Setters ─────────────────────────────────────────────────

    public function message(string $message): self
    {
        $this->message = $message;

        return $this;
    }

    public function status(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function meta(array $meta): self
    {
        $this->meta = array_merge($this->meta, $meta);

        return $this;
    }

    public function traceId(string $traceId): self
    {
        $this->traceId = $traceId;

        return $this;
    }

    public function debug(array $debug): self
    {
        $this->debugInfo = $debug;

        return $this;
    }

    public function errors(?array $errors): self
    {
        $this->errors = $errors;

        return $this;
    }

    public function header(string $key, string $value): self
    {
        $this->extraHeaders[$key] = $value;

        return $this;
    }

    public function headers(array $headers): self
    {
        $this->extraHeaders = array_merge($this->extraHeaders, $headers);

        return $this;
    }

    // ─── Output ─────────────────────────────────────────────────────────

    /**
     * Convert the response payload to an array.
     *
     * @return array<string, mixed>
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
        // Inherit the trace / correlation ids set by AssignTraceId middleware
        // so the success and error branches always share a single identifier.
        // Controller-supplied overrides (via ->traceId()) keep precedence.
        if ($request instanceof Request) {
            if ($this->traceId === null) {
                $attribute = $request->attributes->get('trace_id');
                if (is_string($attribute) && $attribute !== '') {
                    $this->traceId = $attribute;
                }
            }

            $correlationId = $request->attributes->get('correlation_id');
            if (is_string($correlationId) && $correlationId !== '' && ! isset($this->extraHeaders['X-Correlation-ID'])) {
                $this->extraHeaders['X-Correlation-ID'] = $correlationId;
            }
        }

        $headers = $this->extraHeaders;
        if ($this->traceId !== null && ! isset($headers['X-Request-ID'])) {
            $headers['X-Request-ID'] = $this->traceId;
        }

        $response = response()->json($this->toArray(), $this->status);
        foreach ($headers as $key => $value) {
            $response->headers->set($key, $value);
        }

        return $response;
    }
}
