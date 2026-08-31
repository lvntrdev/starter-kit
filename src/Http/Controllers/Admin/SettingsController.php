<?php

namespace Lvntr\StarterKit\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Passport\Passport;
use Lvntr\StarterKit\Domain\Setting\Actions\SendTestMailAction;
use Lvntr\StarterKit\Domain\Setting\Actions\UpdateAuthSettingsAction;
use Lvntr\StarterKit\Domain\Setting\Actions\UpdateSettingsAction;
use Lvntr\StarterKit\Domain\Setting\DTOs\ApidogSettingsDTO;
use Lvntr\StarterKit\Domain\Setting\DTOs\AppearanceSettingsDTO;
use Lvntr\StarterKit\Domain\Setting\DTOs\AuthSettingsDTO;
use Lvntr\StarterKit\Domain\Setting\DTOs\FileManagerSettingsDTO;
use Lvntr\StarterKit\Domain\Setting\DTOs\GeneralSettingsDTO;
use Lvntr\StarterKit\Domain\Setting\DTOs\MailSettingsDTO;
use Lvntr\StarterKit\Domain\Setting\DTOs\PostmanSettingsDTO;
use Lvntr\StarterKit\Domain\Setting\DTOs\StorageSettingsDTO;
use Lvntr\StarterKit\Domain\Setting\DTOs\TurnstileSettingsDTO;
use Lvntr\StarterKit\Domain\Setting\Queries\SettingsDefaultsQuery;
use Lvntr\StarterKit\Domain\Setting\SettingService;
use Lvntr\StarterKit\Exceptions\ApiException;
use Lvntr\StarterKit\Http\Requests\Admin\Settings\SendTestMailRequest;
use Lvntr\StarterKit\Http\Requests\Admin\Settings\UpdateApidogSettingsRequest;
use Lvntr\StarterKit\Http\Requests\Admin\Settings\UpdateAppearanceSettingsRequest;
use Lvntr\StarterKit\Http\Requests\Admin\Settings\UpdateAuthSettingsRequest;
use Lvntr\StarterKit\Http\Requests\Admin\Settings\UpdateFileManagerSettingsRequest;
use Lvntr\StarterKit\Http\Requests\Admin\Settings\UpdateGeneralSettingsRequest;
use Lvntr\StarterKit\Http\Requests\Admin\Settings\UpdateMailSettingsRequest;
use Lvntr\StarterKit\Http\Requests\Admin\Settings\UpdatePostmanSettingsRequest;
use Lvntr\StarterKit\Http\Requests\Admin\Settings\UpdateStorageSettingsRequest;
use Lvntr\StarterKit\Http\Requests\Admin\Settings\UpdateTurnstileSettingsRequest;
use Lvntr\StarterKit\Http\Requests\Admin\Settings\UploadAppearanceLogoRequest;
use Lvntr\StarterKit\Http\Requests\Admin\Settings\UploadFaviconRequest;
use Lvntr\StarterKit\Http\Requests\Admin\Settings\UploadLogoRequest;
use Lvntr\StarterKit\Http\Responses\ApiResponse;
use Lvntr\StarterKit\Support\ThemeResolver;

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
    public function __construct(
        private readonly SettingService $settings,
    ) {}

    /**
     * Display the settings page with all groups.
     */
    public function index(SettingsDefaultsQuery $query, Request $request): Response
    {
        // OAuth/Token tab'ları ve sistem sağlığı yalnızca system_admin için
        // render ediliyor; payload'u şişirmemek için diğer rollerde boş gönder.
        $isSystemAdmin = (bool) $request->user()?->hasRole('system_admin');

        return Inertia::render('Admin/Settings/Index', [
            'settings' => $query->all(),
            'timezones' => \DateTimeZone::listIdentifiers(),
            'availableLanguages' => config('app.available_languages', ['en' => 'English']),
            'availableScopes' => $isSystemAdmin ? Passport::scopes()->values() : [],
            'healthReport' => $isSystemAdmin ? session('doctor_report') : null,
        ]);
    }

    /**
     * Update general settings.
     */
    public function updateGeneral(UpdateGeneralSettingsRequest $request, UpdateSettingsAction $action): RedirectResponse
    {
        $action->execute('general', GeneralSettingsDTO::fromArray($request->validated()));

        return back()->with('success', __('sk-setting.flash.general'));
    }

    /**
     * Update authentication settings.
     */
    public function updateAuth(UpdateAuthSettingsRequest $request, UpdateAuthSettingsAction $action): RedirectResponse
    {
        $action->execute(AuthSettingsDTO::fromArray($request->validated()));

        return back()->with('success', __('sk-setting.flash.auth'));
    }

    /**
     * Update mail settings.
     */
    public function updateMail(UpdateMailSettingsRequest $request, UpdateSettingsAction $action): RedirectResponse
    {
        $action->execute('mail', MailSettingsDTO::fromArray($request->validated()));

        return back()->with('success', __('sk-setting.flash.mail'));
    }

    /**
     * Update storage settings.
     */
    public function updateStorage(UpdateStorageSettingsRequest $request, UpdateSettingsAction $action): RedirectResponse
    {
        $action->execute('storage', StorageSettingsDTO::fromArray($request->validated()));

        return back()->with('success', __('sk-setting.flash.storage'));
    }

    /**
     * Update FileManager settings.
     */
    public function updateFileManager(UpdateFileManagerSettingsRequest $request, UpdateSettingsAction $action): RedirectResponse
    {
        $action->execute('file_manager', FileManagerSettingsDTO::fromArray($request->validated()));

        return back()->with('success', __('sk-setting.flash.file_manager'));
    }

    /**
     * Update turnstile settings.
     */
    public function updateTurnstile(UpdateTurnstileSettingsRequest $request, UpdateSettingsAction $action): RedirectResponse
    {
        $action->execute('turnstile', TurnstileSettingsDTO::fromArray($request->validated()));

        return back()->with('success', __('sk-setting.flash.turnstile'));
    }

    /**
     * Update Postman integration settings.
     */
    public function updatePostman(UpdatePostmanSettingsRequest $request, UpdateSettingsAction $action): RedirectResponse
    {
        $action->execute('postman', PostmanSettingsDTO::fromArray($request->validated()));

        return back()->with('success', __('sk-setting.flash.postman'));
    }

    /**
     * Update Apidog integration settings.
     */
    public function updateApidog(UpdateApidogSettingsRequest $request, UpdateSettingsAction $action): RedirectResponse
    {
        $action->execute('apidog', ApidogSettingsDTO::fromArray($request->validated()));

        return back()->with('success', __('sk-setting.flash.apidog'));
    }

    /**
     * Update appearance settings (theme, accent, dark-mode default, sidebar).
     *
     * Persists the appearance group, then reconciles the build-time theme
     * marker against the two-layer theme model:
     *
     *   - A RUNTIME theme (main/aura) is applied live via `data-sk-theme` and
     *     needs no rebuild, so the marker is reset to the default (`main`). This
     *     neutralizes the build-time slot layer — coming back from a custom
     *     theme, `_active.css` then resolves deterministically to `main` instead
     *     of leaving a stale custom build active underneath the runtime layer.
     *   - A build-time CUSTOM theme keeps the legacy behavior: write its marker
     *     so the next build resolves it.
     *
     * The theme name is slug-validated both by the FormRequest (must be a
     * member of ThemeResolver::availableThemes()) and by ThemeResolver before
     * the marker write — no traversal value can reach the path-segment file.
     */
    public function updateAppearance(UpdateAppearanceSettingsRequest $request, UpdateSettingsAction $action): RedirectResponse
    {
        $dto = AppearanceSettingsDTO::fromArray($request->validated());

        $action->execute('appearance', $dto);

        // Bridge the DB choice to the build marker the node resolver reads
        // (sk-theme-build.mjs). Runtime themes neutralize the build layer
        // (marker → default); custom themes select themselves for the next
        // build. Validation already restricted $dto->theme to an installed,
        // slug-safe theme; writeMarker re-validates defensively.
        ThemeResolver::writeMarker(
            ThemeResolver::isRuntimeTheme($dto->theme)
                ? ThemeResolver::DEFAULT_THEME
                : $dto->theme
        );

        return back()->with('success', __('sk-setting.flash.appearance'));
    }

    /**
     * Upload application logo.
     */
    public function uploadLogo(UploadLogoRequest $request): ApiResponse|JsonResponse
    {
        $path = $this->storeUploadedFile($request->file('logo'), 'logo');
        $this->replaceStoredFile('general.logo', $path);

        return to_api(['logo_url' => $this->publicUrl($path)], __('sk-setting.flash.logo_uploaded'));
    }

    /**
     * Delete application logo.
     */
    public function deleteLogo(): ApiResponse|JsonResponse
    {
        $path = $this->settings->getValue('general.logo');
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }

        $this->settings->setValue('general.logo', null);

        return to_api(status: 204);
    }

    /**
     * Upload an appearance logo variant (`logo_light` | `logo_dark`).
     *
     * Stored under the `appearance/` directory on the public disk. SVG is
     * intentionally excluded (same rationale as uploadLogo) — it can embed
     * <script>/onload and execute in the app origin when served publicly.
     */
    public function uploadAppearanceLogo(UploadAppearanceLogoRequest $request, string $variant): ApiResponse|JsonResponse
    {
        $key = $this->appearanceLogoKey($variant);

        $path = $this->storeUploadedFile($request->file('logo'), 'appearance');
        $this->replaceStoredFile($key, $path);

        return to_api(['logo_url' => $this->publicUrl($path)], __('sk-setting.flash.logo_uploaded'));
    }

    /**
     * Delete an appearance logo variant (`logo_light` | `logo_dark`).
     */
    public function deleteAppearanceLogo(string $variant): ApiResponse|JsonResponse
    {
        $key = $this->appearanceLogoKey($variant);

        $this->deleteStoredFile($key);
        $this->settings->setValue($key, null);

        return to_api(status: 204);
    }

    /**
     * Upload the favicon.
     *
     * Accepts png/ico only (no svg, no jpeg/webp — favicons are small raster
     * or .ico). Stored under `appearance/`. `.ico` is not an `image` per the
     * validator's GD check, so the rule is mimes-only, not `image`.
     */
    public function uploadFavicon(UploadFaviconRequest $request): ApiResponse|JsonResponse
    {
        $path = $this->storeUploadedFile($request->file('favicon'), 'appearance');
        $this->replaceStoredFile('appearance.favicon', $path);

        return to_api(['favicon_url' => $this->publicUrl($path)], __('sk-setting.flash.favicon_uploaded'));
    }

    /**
     * Delete the favicon.
     */
    public function deleteFavicon(): ApiResponse|JsonResponse
    {
        $this->deleteStoredFile('appearance.favicon');
        $this->settings->setValue('appearance.favicon', null);

        return to_api(status: 204);
    }

    /**
     * Resolve the settings key for an appearance logo variant, rejecting any
     * value outside the known set so the variant cannot be used to write an
     * arbitrary settings key.
     */
    private function appearanceLogoKey(string $variant): string
    {
        return match ($variant) {
            'light' => 'appearance.logo_light',
            'dark' => 'appearance.logo_dark',
            default => abort(404),
        };
    }

    /**
     * Store an uploaded file on the public disk, failing loudly when the write
     * does not produce a path.
     */
    private function storeUploadedFile(mixed $file, string $directory): string
    {
        $path = $file instanceof UploadedFile ? $file->store($directory, 'public') : false;

        // store() returns false when the disk write fails. Bailing out HERE is
        // what keeps the currently-referenced asset intact: the caller only
        // swaps the setting (and drops the old file) once a new path exists.
        if (! is_string($path) || $path === '') {
            throw ApiException::serverError(__('sk-setting.flash.upload_failed'));
        }

        return $path;
    }

    /**
     * Point a setting at a freshly stored file, then drop the one it replaced.
     *
     * The order matters. Deleting first — which is what these endpoints used to
     * do — loses the existing asset outright when the new store() fails, leaving
     * the setting pointing at a path that no longer exists. Writing the setting
     * first means a failed delete only leaves an orphan behind, which is
     * recoverable; a deleted-then-failed upload is not.
     */
    private function replaceStoredFile(string $key, string $newPath): void
    {
        $previous = $this->settings->getValue($key);

        $this->settings->setValue($key, $newPath);

        if (is_string($previous) && $previous !== '' && $previous !== $newPath && Storage::disk('public')->exists($previous)) {
            Storage::disk('public')->delete($previous);
        }
    }

    /**
     * Delete the file currently referenced by a settings key from the public
     * disk, if it exists. No-op when the key is empty.
     */
    private function deleteStoredFile(string $key): void
    {
        $path = $this->settings->getValue($key);
        if (is_string($path) && $path !== '' && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * Resolve a public-disk asset URL, guarding against mixed-content.
     *
     * The public disk builds its URL from APP_URL / the disk `url` config. When
     * that base is `http://` but the page is served over HTTPS, the browser
     * blocks the asset as mixed content, so `http://` URLs are rewritten to
     * protocol-relative `//…` and inherit the page scheme. An `https://` URL is
     * kept as-is: an https asset is never mixed content on any page, while
     * stripping its scheme would let an http page downgrade the request.
     * Relative/path-only URLs pass through.
     */
    private function publicUrl(string $path): string
    {
        $url = Storage::disk('public')->url($path);

        if (str_starts_with($url, 'http://')) {
            return substr($url, 5);   // 'http://...' → '//...'
        }

        return $url;
    }

    /**
     * Send a test email using current mail settings.
     */
    public function testMail(SendTestMailRequest $request, SendTestMailAction $action): RedirectResponse
    {
        try {
            $action->execute($request->input('test_email'));

            return back()->with('success', __('sk-setting.flash.test_mail_sent'));
        } catch (\Throwable $e) {
            // SMTP exceptions often include host/username/TLS details. Keep
            // that context in the server log but do not flash it back to
            // the admin — return a generic failure instead.
            Log::error('Test mail failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return back()->with('error', __('sk-setting.flash.test_mail_failed'));
        }
    }
}
