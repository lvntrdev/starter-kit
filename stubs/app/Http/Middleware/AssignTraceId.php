<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns a per-request trace id so success and error paths can share a
 * single correlation identifier.
 *
 * - Generates a fresh UUID on entry and stores it under the `trace_id`
 *   request attribute. `ApiResponse::toResponse()` and
 *   `ApiExceptionHandler` both pick it up from there, guaranteeing the
 *   `X-Request-ID` header and the body-level `trace_id` agree.
 * - A sanitised client-supplied `X-Request-ID` is stored under the
 *   `correlation_id` attribute and echoed back on the response as
 *   `X-Correlation-ID` so callers can stitch their own logs to ours
 *   without the security risk of trusting arbitrary client input as the
 *   authoritative trace id.
 * - Designed for the `api` middleware group (JSON responses); harmless
 *   if wired into the `web` group, but the header contract is aimed at
 *   API clients.
 */
class AssignTraceId
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $traceId = (string) Str::uuid();
        $request->attributes->set('trace_id', $traceId);

        $correlationId = $this->sanitizeClientRequestId($request->header('X-Request-ID'));
        if ($correlationId !== null) {
            $request->attributes->set('correlation_id', $correlationId);
        }

        $response = $next($request);

        if ($response instanceof Response) {
            if (! $response->headers->has('X-Request-ID')) {
                $response->headers->set('X-Request-ID', $traceId);
            }

            if ($correlationId !== null && ! $response->headers->has('X-Correlation-ID')) {
                $response->headers->set('X-Correlation-ID', $correlationId);
            }
        }

        return $response;
    }

    /**
     * Only accept client-supplied ids that match a safe charset (letters,
     * digits, dash, underscore, dot) and stay within 128 chars. This mirrors
     * the guard used in ApiExceptionHandler to defeat log/header injection.
     */
    private function sanitizeClientRequestId(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $trimmed = substr($value, 0, 128);

        return preg_match('/^[A-Za-z0-9._-]+$/', $trimmed) === 1 ? $trimmed : null;
    }
}
