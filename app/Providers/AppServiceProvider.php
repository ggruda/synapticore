<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
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
        // Register admin gates
        \Illuminate\Support\Facades\Gate::define('admin', [\App\Policies\AdminPolicy::class, 'admin']);
        \Illuminate\Support\Facades\Gate::define('manage-projects', [\App\Policies\AdminPolicy::class, 'manageProjects']);
        \Illuminate\Support\Facades\Gate::define('manage-tickets', [\App\Policies\AdminPolicy::class, 'manageTickets']);
        \Illuminate\Support\Facades\Gate::define('manage-workflows', [\App\Policies\AdminPolicy::class, 'manageWorkflows']);
        \Illuminate\Support\Facades\Gate::define('view-artifacts', [\App\Policies\AdminPolicy::class, 'viewArtifacts']);
        \Illuminate\Support\Facades\Gate::define('download-artifacts', [\App\Policies\AdminPolicy::class, 'downloadArtifacts']);
    }
}
