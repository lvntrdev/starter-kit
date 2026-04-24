<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\HtmlSanitizer;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        // Defense-in-depth: the stored value is already sanitized by
        // SettingService::setValue (and UpdateGeneralSettingsRequest), but
        // sanitize again on read so a historically-poisoned row or a
        // future direct-write path can never reach the Dashboard's v-html.
        $stored = Setting::getValue('general.welcome_message');
        $welcomeMessage = is_string($stored) ? HtmlSanitizer::clean($stored) : null;

        return Inertia::render('Admin/Dashboard/Index', [
            'welcomeMessage' => $welcomeMessage === '' ? null : $welcomeMessage,
        ]);
    }
}
