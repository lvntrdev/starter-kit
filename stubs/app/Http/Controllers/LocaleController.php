<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    /**
     * Switch the user's interface language. The locale must be one of the
     * active languages configured in the admin settings panel.
     */
    public function update(Request $request): RedirectResponse
    {
        $available = array_keys(config('app.languages', []));

        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in($available)],
        ]);

        $request->session()->put('locale', $validated['locale']);

        return back();
    }
}
