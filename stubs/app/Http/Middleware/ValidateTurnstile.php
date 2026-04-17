<?php

namespace App\Http\Middleware;

use App\Rules\TurnstileRule;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ValidateTurnstile
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('services.turnstile.enabled')) {
            return $next($request);
        }

        $errors = [];
        (new TurnstileRule)->validate(
            'cf_turnstile_response',
            $request->input('cf_turnstile_response'),
            function (string $message) use (&$errors): void {
                $errors[] = $message;
            },
        );

        if ($errors !== []) {
            throw ValidationException::withMessages(['cf_turnstile_response' => $errors]);
        }

        return $next($request);
    }
}
