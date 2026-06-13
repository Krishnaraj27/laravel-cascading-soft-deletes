<?php

namespace Krishnaraj\LaravelCascadingSoftDeletes;

use Illuminate\Support\ServiceProvider;

class CascadingSoftDeletesServiceProvider extends ServiceProvider
{
    /**
     * Register package services.
     *
     * Merges the package's default configuration so that users can override
     * individual values without publishing the entire config file.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/cascading-soft-deletes.php', 'cascading-soft-deletes'
        );
    }

    /**
     * Bootstrap package services.
     *
     * Loads the package's database migrations and registers publishable
     * assets (config and migrations) for the consuming application.
     *
     * @return void
     */
    public function boot(): void
    {
        // Load package migrations automatically so the tracking table
        // is available without the user needing to publish migrations.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/cascading-soft-deletes.php' => config_path('cascading-soft-deletes.php'),
            ], 'cascading-soft-deletes-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'cascading-soft-deletes-migrations');
        }
    }
}