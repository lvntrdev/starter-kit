<?php

use App\Http\Controllers\Api\Auth\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->controller(AuthController::class)->group(function () {
    // `turnstile` middleware is a no-op when Cloudflare Turnstile is
    // disabled in settings, so enabling it here is safe for existing
    // clients; once an operator turns Turnstile on it immediately covers
    // the API register and login endpoints in addition to the web flow.
    // Throttle stays FIRST in the middleware list so a flood cannot force one
    // outbound Cloudflare verification per request.
    //
    // Rate limits:
    //   login → the `api-login` named limiter, registered by the package
    //     (Lvntr\StarterKit\StarterKitServiceProvider) so that refreshing this
    //     published file can never point it at a limiter nobody declared:
    //     5/min per IP — the endpoint's previous fixed ceiling, kept — PLUS
    //     3/min per email address. The per-email limit is what stops an
    //     attacker spreading guesses for one account across many IPs. It is a
    //     dedicated limiter rather than the web `login` one so that the
    //     `auth.login_throttle` setting can never relax the API, and so the
    //     API does not inherit the web limiter's looser 10/min per IP.
    //   register, two-factor-challenge → plain `throttle:5,1` (per IP).
    //     `register` has no account identity to key on — the account does not
    //     exist yet, so a per-email bucket would only cost a legitimate retry.
    //     The 2FA challenge is single-use and keyed to a short-lived,
    //     server-issued challenge id (claimed atomically in
    //     TwoFactorChallengeAction), so the IP cap is the only axis an
    //     attacker can move.
    //
    // `register` stays UNCONDITIONALLY registered even when the
    // `auth.registration` setting is off. RegisterUserAction enforces the
    // setting and answers 403; registering the route conditionally would turn
    // that into a 404 and break the client error-handling contract (a client
    // cannot tell "feature disabled" from "wrong URL / wrong API version").
    Route::post('register', 'register')
        ->name('register')
        ->middleware(['throttle:5,1', 'turnstile']);
    Route::post('login', 'login')
        ->name('login')
        ->middleware(['throttle:api-login', 'turnstile']);
    Route::post('two-factor-challenge', 'twoFactorChallenge')
        ->name('twoFactorChallenge')
        ->middleware('throttle:5,1');
});
