<?php

namespace App\Exceptions;

use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Centralized exception handler for API requests.
 *
 * Returns all API errors in a consistent JSON format:
 * {
 *     "success": false,
 *     "status": 404,
 *     "message": "User not found.",
 *     "data": null,
 *     "errors": { ... },       // only on validation errors
 *     "trace_id": "uuid",      // request correlation ID
 *     "debug": { ... }         // only when APP_DEBUG=true
 * }
 */
class ApiExceptionHandler
{
    /**
     * Register the exception handler in bootstrap/app.php.
     */
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            // Only handle API requests or requests expecting JSON
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return self::handle($e, $request);
        });
    }

    /**
     * Convert an exception to a JsonResponse.
     */
    private static function handle(Throwable $e, Request $request): JsonResponse
    {
        // 1. Trace ID — reuse the request attribute set by AssignTraceId so the
        //    success-path and error-path share the same identifier. Falls back
        //    to a fresh UUID if the middleware did not run (e.g. early failure).
        $traceId = self::resolveTraceId($request);
        $clientRequestId = self::sanitizeClientRequestId($request->header('X-Request-ID'));

        // 2. Status + Message mapping
        [$status, $message] = self::resolve($e);

        // 3. Logging — 500+ non-validation errors
        if ($status >= 500 && ! ($e instanceof ValidationException)) {
            Log::error("[API {$status}] {$message}", [
                'trace_id' => $traceId,
                'client_request_id' => $clientRequestId,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'ip' => $request->ip(),
                'user_id' => $request->user()?->id,
            ]);
        }

        // 4. Build the response
        $response = ApiResponse::error($message, $status)
            ->traceId($traceId);

        // Validation errors
        if ($e instanceof ValidationException) {
            $response->errors($e->errors());
        }

        // Rate-limit headers propagated from ThrottleRequestsException so clients
        // can honour Retry-After and the standard X-RateLimit-* contract.
        if ($e instanceof ThrottleRequestsException) {
            foreach ($e->getHeaders() as $header => $value) {
                $response->header($header, (string) $value);
            }
        }

        // Correlation header — echo the sanitised client-supplied ID so the
        // caller can match its own log records to the server-side trace id.
        if ($clientRequestId !== null) {
            $response->header('X-Correlation-ID', $clientRequestId);
        }

        // Debug info — only in development environment
        if (config('app.debug', false) && $status >= 400) {
            $response->debug([
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
                'trace' => collect($e->getTrace())->take(5)->map(fn ($frame) => [
                    'file' => ($frame['file'] ?? '?').':'.($frame['line'] ?? '?'),
                    'call' => ($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'].'()',
                ])->all(),
            ]);
        }

        return $response
            ->header('X-Request-ID', $traceId)
            ->toResponse($request);
    }

    /**
     * Resolve [status, message] pair from the exception type.
     *
     * Match branch order matters: ApiException extends HttpException, so it
     * must be checked before HttpExceptionInterface — otherwise custom API
     * exceptions would be routed through the generic abort() handling.
     *
     * @return array{int, string}
     */
    private static function resolve(Throwable $e): array
    {
        return match (true) {
            // Our custom API exception — carries its own curated message and status.
            $e instanceof ApiException => [
                $e->getStatusCode(),
                $e->getMessage(),
            ],

            // Model not found (findOrFail, firstOrFail)
            $e instanceof ModelNotFoundException => [
                404,
                self::modelNotFoundMessage($e),
            ],

            // Route not found — check for nested exception
            $e instanceof NotFoundHttpException => [
                404,
                $e->getPrevious() instanceof ModelNotFoundException
                    ? self::modelNotFoundMessage($e->getPrevious())
                    : 'Endpoint not found.',
            ],

            // Validation error
            $e instanceof ValidationException => [
                422,
                'Validation error.',
            ],

            // HTTP method not allowed
            $e instanceof MethodNotAllowedHttpException => [
                405,
                'This HTTP method is not allowed for this endpoint.',
            ],

            // Authentication
            $e instanceof AuthenticationException => [
                401,
                'Authentication required.',
            ],

            // Authorization
            $e instanceof AuthorizationException => [
                403,
                'You are not authorized for this action.',
            ],

            // Rate limiting — interpolate Retry-After when available.
            $e instanceof ThrottleRequestsException => [
                429,
                isset($e->getHeaders()['Retry-After'])
                    ? 'Too many requests. Please try again after '.$e->getHeaders()['Retry-After'].' seconds.'
                    : 'Too many requests. Please try again later.',
            ],

            // Other Symfony HttpExceptions (abort() calls). The raw exception
            // message is dropped on purpose so internal details (SQL, paths,
            // stack context) never leak — developers should throw an
            // ApiException with a curated message instead.
            $e instanceof HttpExceptionInterface => [
                $e->getStatusCode(),
                self::defaultMessageForStatus($e->getStatusCode()),
            ],

            // Unexpected errors — never leak the raw exception message into
            // the API response; detailed info goes into the debug block when
            // APP_DEBUG is on.
            default => [
                500,
                'A server error occurred.',
            ],
        };
    }

    /**
     * Look up the trace id assigned by AssignTraceId middleware, generating
     * one on the fly if the middleware did not run for this request path.
     */
    private static function resolveTraceId(Request $request): string
    {
        $existing = $request->attributes->get('trace_id');

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $generated = (string) Str::uuid();
        $request->attributes->set('trace_id', $generated);

        return $generated;
    }

    /**
     * Accept a client-provided X-Request-ID only if it matches a safe charset
     * (letters, digits, dash, underscore, dot) and is ≤ 128 chars long.
     * Anything else is discarded to avoid log / header injection.
     */
    private static function sanitizeClientRequestId(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $trimmed = substr($value, 0, 128);

        return preg_match('/^[A-Za-z0-9._-]+$/', $trimmed) === 1 ? $trimmed : null;
    }

    /**
     * Build a human-readable message from a ModelNotFoundException, using the
     * short model class name so clients get "User not found." rather than a
     * generic fallback. Falls back to a neutral message when the model name
     * cannot be resolved.
     */
    private static function modelNotFoundMessage(ModelNotFoundException|Throwable $e): string
    {
        if (! ($e instanceof ModelNotFoundException)) {
            return 'The requested resource was not found.';
        }

        $model = $e->getModel();

        if (! is_string($model) || $model === '') {
            return 'The requested resource was not found.';
        }

        return class_basename($model).' not found.';
    }

    /**
     * Default message for a given HTTP status code.
     */
    private static function defaultMessageForStatus(int $status): string
    {
        return match ($status) {
            400 => 'Bad request.',
            401 => 'Authentication required.',
            403 => 'You are not authorized for this action.',
            404 => 'Not found.',
            405 => 'HTTP method not allowed.',
            408 => 'Request timeout.',
            409 => 'Conflict — record already exists.',
            422 => 'Unprocessable entity.',
            429 => 'Too many requests.',
            500 => 'A server error occurred.',
            502 => 'Bad gateway.',
            503 => 'Service unavailable.',
            default => 'An error occurred.',
        };
    }
}
