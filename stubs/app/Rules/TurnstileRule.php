<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;

class TurnstileRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! config('services.turnstile.enabled')) {
            return;
        }

        if (empty($value)) {
            $fail(__('sk-auth.turnstile.required'));

            return;
        }

        try {
            $response = Http::asForm()
                ->timeout(5)
                ->post(config('services.turnstile.verify_url'), [
                    'secret' => config('services.turnstile.secret_key'),
                    'response' => $value,
                    'remoteip' => request()->ip(),
                ]);
        } catch (\Throwable $e) {
            report($e);
            $fail(__('sk-auth.turnstile.failed'));

            return;
        }

        if (! $response->successful() || $response->json('success') !== true) {
            $fail(__('sk-auth.turnstile.failed'));
        }
    }
}
