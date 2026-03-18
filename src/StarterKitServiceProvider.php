<?php

namespace Lvntr\StarterKit;

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
        $this->registerCommands();
        $this->registerTranslations();
        $this->registerPublishables();
        $this->registerMigrations();
        $this->registerViews();
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
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'starter-kit');
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
