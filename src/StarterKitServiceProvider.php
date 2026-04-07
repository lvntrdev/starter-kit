<?php

namespace Lvntr\StarterKit;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class StarterKitServiceProvider extends ServiceProvider
{
    /**
     * Register package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/starter-kit.php', 'starter-kit');
    }

    /**
     * Bootstrap package services.
     */
    public function boot(): void
    {
        $this->configureModels();
        $this->configurePassport();
        $this->configureGates();
        $this->configureRateLimiting();
        $this->configureScramble();
        $this->registerCommands();
        $this->registerTranslations();
        $this->registerPublishables();
        $this->registerMigrations();
        $this->registerViews();
    }

    /**
     * Configure Eloquent strict mode.
     */
    private function configureModels(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
    }

    /**
     * Configure Passport token lifetimes.
     */
    private function configurePassport(): void
    {
        if (! class_exists('Laravel\Passport\Passport')) {
            return;
        }

        \Laravel\Passport\Passport::tokensExpireIn(now()->addDays(15));
        \Laravel\Passport\Passport::refreshTokensExpireIn(now()->addDays(30));
        \Laravel\Passport\Passport::personalAccessTokensExpireIn(now()->addMonths(6));
    }

    /**
     * Configure authorization gates.
     */
    private function configureGates(): void
    {
        if (! class_exists('App\Enums\RoleEnum') || ! class_exists('App\Models\User')) {
            return;
        }

        $systemAdminRole = \App\Enums\RoleEnum::SystemAdmin;

        Gate::before(function (\App\Models\User $user) use ($systemAdminRole): ?bool {
            return $user->hasRole($systemAdminRole) ? true : null;
        });

        Gate::define('viewPulse', function (\App\Models\User $user) use ($systemAdminRole) {
            return $user->hasRole($systemAdminRole);
        });
    }

    /**
     * Configure rate limiters.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }

    /**
     * Configure Scramble API documentation.
     */
    private function configureScramble(): void
    {
        \Dedoc\Scramble\Scramble::configure()
            ->withDocumentTransformers(function (\Dedoc\Scramble\Support\Generator\OpenApi $openApi) {
                $openApi->secure(
                    \Dedoc\Scramble\Support\Generator\SecurityScheme::http('bearer')
                );
            });

        Gate::define('viewApiDocs', function (\App\Models\User $user) {
            return $user->hasPermissionTo('api-docs.read');
        });
    }

    /**
     * Register Artisan commands.
     * Domain commands are available but never published.
     */
    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\Commands\InstallCommand::class,
                Console\Commands\UpdateCommand::class,
                Console\Commands\UpgradeCommand::class,
                Console\Commands\PublishCommand::class,
                Console\Commands\MakeDomainCommand::class,
                Console\Commands\RemoveDomainCommand::class,
                Console\Commands\EnvSyncCommand::class,
            ]);
        }
    }

    /**
     * Register translation/lang files.
     * Loaded from package namespace: __('starter-kit::admin.menu.dashboard')
     * Users can override by publishing to lang/vendor/starter-kit/
     */
    private function registerTranslations(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'starter-kit');
        $this->loadJsonTranslationsFrom(__DIR__.'/../resources/lang');
    }

    /**
     * Register publishable resources.
     */
    private function registerPublishables(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        // Config
        $this->publishes([
            __DIR__.'/../config/starter-kit.php' => config_path('starter-kit.php'),
        ], 'starter-kit-config');

        // Lang files (optional publish for customization)
        $this->publishes([
            __DIR__.'/../resources/lang' => $this->app->langPath('vendor/starter-kit'),
        ], 'starter-kit-lang');

        // Vue components (optional publish for customization)
        $this->publishes([
            __DIR__.'/../resources/js/components' => resource_path('js/components/Lvntr-Starter-Kit'),
        ], 'starter-kit-components');
    }

    /**
     * Register package migrations.
     */
    private function registerMigrations(): void
    {
        if ($this->app->runningInConsole() && config('starter-kit.run_migrations', false)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }

    /**
     * Register package views (Blade templates).
     */
    private function registerViews(): void
    {
        $viewPath = __DIR__.'/../resources/views';

        if (is_dir($viewPath)) {
            $this->loadViewsFrom($viewPath, 'starter-kit');
        }
    }

    /**
     * Get the package base path.
     */
    public static function basePath(string $path = ''): string
    {
        return dirname(__DIR__).($path ? DIRECTORY_SEPARATOR.$path : '');
    }

    /**
     * Get the stubs path.
     */
    public static function stubsPath(string $path = ''): string
    {
        return static::basePath('stubs').($path ? DIRECTORY_SEPARATOR.$path : '');
    }
}
