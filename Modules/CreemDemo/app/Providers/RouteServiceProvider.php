<?php

namespace Modules\CreemDemo\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

/**
 * Route Service Provider for Creem Demo Module.
 *
 * Automatically registers web routes.
 */
class RouteServiceProvider extends ServiceProvider
{
    /**
     * Module namespace for controllers.
     */
    protected string $moduleNamespace = 'Modules\CreemDemo\Http\Controllers';

    /**
     * Called before routes are registered.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Define the routes for the application.
     */
    public function map(): void
    {
        $this->mapWebRoutes();
    }

    /**
     * Define the "web" routes for the application.
     */
    protected function mapWebRoutes(): void
    {
        Route::middleware('web')
            ->namespace($this->moduleNamespace)
            ->group(module_path('CreemDemo', 'routes/web.php'));
    }
}
