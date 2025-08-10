<?php

namespace App\Providers;

use App\Scout\Engines\PgVectorEngine;
use Illuminate\Support\ServiceProvider;
use Laravel\Scout\EngineManager;

class ScoutServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        resolve(EngineManager::class)->extend('pgvector', function () {
            return new PgVectorEngine;
        });
    }
}
