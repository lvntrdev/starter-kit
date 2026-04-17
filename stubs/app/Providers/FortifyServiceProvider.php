<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Actions\Fortify\ValidateTurnstile;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\EnsureLoginIsNotThrottled;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // ── Inertia View Bindings ────────────────────────────────────
        Fortify::loginView(fn () => Inertia::render('Auth/Login', [
            'status' => session('status'),
        ]));

        Fortify::registerView(function () {
            abort_unless(Features::enabled(Features::registration()), 404);

            return Inertia::render('Auth/Register');
        });

        Fortify::requestPasswordResetLinkView(function () {
            abort_unless(Features::enabled(Features::resetPasswords()), 404);

            return Inertia::render('Auth/ForgotPassword', [
                'status' => session('status'),
            ]);
        });

        Fortify::resetPasswordView(function (Request $request) {
            abort_unless(Features::enabled(Features::resetPasswords()), 404);

            return Inertia::render('Auth/ResetPassword', [
                'token' => $request->route('token'),
                'email' => $request->query('email'),
            ]);
        });

        Fortify::verifyEmailView(function () {
            abort_unless(Features::enabled(Features::emailVerification()), 404);

            return Inertia::render('Auth/VerifyEmail', [
                'status' => session('status'),
            ]);
        });

        Fortify::twoFactorChallengeView(function () {
            abort_unless(Features::enabled(Features::twoFactorAuthentication()), 404);

            return Inertia::render('Auth/TwoFactorChallenge');
        });

        Fortify::confirmPasswordView(fn () => Inertia::render('Auth/ConfirmPassword'));

        // ── authenticateUsing: inactive user block ───────────────────
        Fortify::authenticateUsing(function (Request $request) {
            $user = User::where('email', $request->input(Fortify::username()))->first();

            if (! $user || ! Hash::check($request->password, $user->password)) {
                return null;
            }

            if ($user->status !== 'active') {
                throw ValidationException::withMessages([
                    Fortify::username() => [__('sk-auth.inactive')],
                ]);
            }

            return $user;
        });

        // ── Login pipeline with Turnstile ────────────────────────────
        Fortify::authenticateThrough(fn () => array_filter([
            config('fortify.limiters.login') ? EnsureLoginIsNotThrottled::class : null,
            ValidateTurnstile::class,
            Features::enabled(Features::twoFactorAuthentication()) ? RedirectIfTwoFactorAuthenticatable::class : null,
            AttemptToAuthenticate::class,
            PrepareAuthenticatedSession::class,
        ]));

        // ── forgot-password → turnstile middleware ───────────────────
        Route::matched(function ($event) {
            $request = $event->request;
            if ($request->isMethod('POST') && $request->is('forgot-password')) {
                $event->route->middleware(['turnstile']);
            }
        });

        // ── Rate Limiters ────────────────────────────────────────────
        RateLimiter::for('login', function (Request $request) {
            $email = Str::transliterate(Str::lower((string) $request->input(Fortify::username())));
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(10)->by('ip:'.$ip),
                Limit::perMinute(5)->by('email-ip:'.$email.'|'.$ip),
                Limit::perMinute(3)->by('email:'.$email),
            ];
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });
    }
}
