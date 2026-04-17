<?php

namespace App\Providers;

use App\Domain\FileManager\Support\ContextRegistry;
use App\Listeners\UpdateLastLogin;
use App\Support\Translation\SkAttributeTranslationLoader;
use Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Only available in main project (dev tooling) — never required by consumers.
        if ($this->app->environment('local') && class_exists(IdeHelperServiceProvider::class)) {
            $this->app->register(IdeHelperServiceProvider::class);
        }

        $this->app->singleton(ContextRegistry::class);

        $this->app->extend('translation.loader', fn ($loader) => new SkAttributeTranslationLoader($loader));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(Login::class, UpdateLastLogin::class);
    }
}
