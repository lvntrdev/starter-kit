<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Setting\Actions\SendTestMailAction;
use App\Domain\Setting\Actions\UpdateAuthSettingsAction;
use App\Domain\Setting\Actions\UpdateSettingsAction;
use App\Domain\Setting\DTOs\AuthSettingsDTO;
use App\Domain\Setting\DTOs\FileManagerSettingsDTO;
use App\Domain\Setting\DTOs\GeneralSettingsDTO;
use App\Domain\Setting\DTOs\MailSettingsDTO;
use App\Domain\Setting\DTOs\StorageSettingsDTO;
use App\Domain\Setting\DTOs\TurnstileSettingsDTO;
use App\Domain\Setting\Queries\SettingsDefaultsQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Settings\SendTestMailRequest;
use App\Http\Requests\Admin\Settings\UpdateAuthSettingsRequest;
use App\Http\Requests\Admin\Settings\UpdateFileManagerSettingsRequest;
use App\Http\Requests\Admin\Settings\UpdateGeneralSettingsRequest;
use App\Http\Requests\Admin\Settings\UpdateMailSettingsRequest;
use App\Http\Requests\Admin\Settings\UpdateStorageSettingsRequest;
use App\Http\Requests\Admin\Settings\UpdateTurnstileSettingsRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin panel settings controller.
 *
 * This controller is intentionally thin:
 *   - Validation → FormRequest
 *   - Data mapping → DTO
 *   - Business logic → Action
 *   - Read queries → Query
 */
class SettingsController extends Controller
{
    /**
     * Display the settings page with all groups.
     */
    public function index(SettingsDefaultsQuery $query): Response
    {
        return Inertia::render('Admin/Settings/Index', [
            'settings' => $query->all(),
            'timezones' => \DateTimeZone::listIdentifiers(),
            'availableLanguages' => config('app.available_languages', ['en' => 'English']),
        ]);
    }

    /**
     * Update general settings.
     */
    public function updateGeneral(UpdateGeneralSettingsRequest $request, UpdateSettingsAction $action): RedirectResponse
    {
        $action->execute('general', GeneralSettingsDTO::fromArray($request->validated()));

        return back()->with('success', 'General settings updated.');
    }

    /**
     * Update authentication settings.
     */
    public function updateAuth(UpdateAuthSettingsRequest $request, UpdateAuthSettingsAction $action): RedirectResponse
    {
        $action->execute(AuthSettingsDTO::fromArray($request->validated()));

        return back()->with('success', 'Authentication settings updated.');
    }

    /**
     * Update mail settings.
     */
    public function updateMail(UpdateMailSettingsRequest $request, UpdateSettingsAction $action): RedirectResponse
    {
        $action->execute('mail', MailSettingsDTO::fromArray($request->validated()));

        return back()->with('success', 'Mail settings updated.');
    }

    /**
     * Update storage settings.
     */
    public function updateStorage(UpdateStorageSettingsRequest $request, UpdateSettingsAction $action): RedirectResponse
    {
        $action->execute('storage', StorageSettingsDTO::fromArray($request->validated()));

        return back()->with('success', 'Storage settings updated.');
    }

    /**
     * Update FileManager settings.
     */
    public function updateFileManager(UpdateFileManagerSettingsRequest $request, UpdateSettingsAction $action): RedirectResponse
    {
        $action->execute('file_manager', FileManagerSettingsDTO::fromArray($request->validated()));

        return back()->with('success', 'File manager settings updated.');
    }

    /**
     * Update turnstile settings.
     */
    public function updateTurnstile(UpdateTurnstileSettingsRequest $request, UpdateSettingsAction $action): RedirectResponse
    {
        $action->execute('turnstile', TurnstileSettingsDTO::fromArray($request->validated()));

        return back()->with('success', 'Turnstile settings updated.');
    }

    /**
     * Upload application logo.
     */
    public function uploadLogo(Request $request): ApiResponse
    {
        // SVG is intentionally excluded — can embed <script>/onload and execute
        // in the app origin when served from the public disk.
        $request->validate([
            'logo' => [
                'required',
                'image',
                'mimes:png,jpg,jpeg,webp',
                'max:2048',
                'dimensions:max_width=4096,max_height=4096',
            ],
        ]);

        // Delete old logo if exists
        $oldLogo = Setting::getValue('general.logo');
        if ($oldLogo && Storage::disk('public')->exists($oldLogo)) {
            Storage::disk('public')->delete($oldLogo);
        }

        $path = $request->file('logo')->store('logo', 'public');
        Setting::setValue('general.logo', $path);

        return to_api(['logo_url' => Storage::disk('public')->url($path)], 'Logo uploaded.');
    }

    /**
     * Delete application logo.
     */
    public function deleteLogo(): JsonResponse
    {
        $path = Setting::getValue('general.logo');
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }

        Setting::setValue('general.logo', null);

        return to_api(status: 204);
    }

    /**
     * Send a test email using current mail settings.
     */
    public function testMail(SendTestMailRequest $request, SendTestMailAction $action): RedirectResponse
    {
        try {
            $action->execute($request->input('test_email'));

            return back()->with('success', 'Test email sent successfully.');
        } catch (\Throwable $e) {
            // SMTP exceptions often include host/username/TLS details. Keep
            // that context in the server log but do not flash it back to
            // the admin — return a generic failure instead.
            Log::error('Test mail failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to send test email. Check the server logs for details.');
        }
    }
}
