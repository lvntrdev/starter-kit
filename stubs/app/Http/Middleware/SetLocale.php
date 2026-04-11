<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Apply the user's preferred locale (stored in session) when it is one
     * of the active languages configured by the admin. Falls back to the
     * default locale set by SettingsServiceProvider.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('locale');

        if ($locale && array_key_exists($locale, config('app.languages', []))) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
