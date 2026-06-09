<?php

namespace Krishnaraj\LaravelCascadingSoftDeletes;

use Illuminate\Support\ServiceProvider;

class CascadingSoftDeletesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/cascading-soft-deletes.php', 'cascading-soft-deletes'
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/cascading-soft-deletes.php' => config_path('cascading-soft-deletes.php'),
            ], 'cascading-soft-deletes-config');
        }
    }
}