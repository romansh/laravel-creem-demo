<?php

namespace Modules\CreemDemo\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\CreemDemo\Listeners\CreemDemoListener;
use Romansh\LaravelCreem\Events\CheckoutCompleted;
use Romansh\LaravelCreem\Events\DisputeCreated;
use Romansh\LaravelCreem\Events\GrantAccess;
use Romansh\LaravelCreem\Events\RefundCreated;
use Romansh\LaravelCreem\Events\RevokeAccess;
use Romansh\LaravelCreem\Events\SubscriptionActive;
use Romansh\LaravelCreem\Events\SubscriptionCanceled;
use Romansh\LaravelCreem\Events\SubscriptionExpired;
use Romansh\LaravelCreem\Events\SubscriptionPaid;
use Romansh\LaravelCreem\Events\SubscriptionPaused;

/**
 * Creem Demo Module Service Provider.
 *
 * The demo module is a PURE OBSERVER:
 *   - Registers listeners on romansh/laravel-creem package Events
 *   - Does NOT own a webhook endpoint (package handles that at POST /creem/webhook)
 *   - All state is stored in session only — no migrations needed
 */
class CreemDemoServiceProvider extends ServiceProvider
{
    protected string $moduleName      = 'CreemDemo';
    protected string $moduleNameLower = 'creemdemo';

    public function boot(): void
    {
        $this->registerConfig();
        $this->registerViews();
        $this->registerTranslations();
        $this->loadMigrationsFrom(module_path($this->moduleName, 'database/migrations'));
        $this->registerLivewireComponents();
        $this->registerEventListeners();
        $this->app->register(\Modules\CreemDemo\Providers\RouteServiceProvider::class);
    }

    public function register(): void {}

    /**
     * Register listeners on romansh/laravel-creem package events.
     *
     * The package's WebhookController verifies the Creem signature and
     * dispatches these events. This module simply listens and writes to session.
     */
    protected function registerEventListeners(): void
    {
        $l = CreemDemoListener::class;

        // CreemEvent subclasses — single $payload constructor
        Event::listen(CheckoutCompleted::class,    [$l, 'onCheckoutCompleted']);
        Event::listen(SubscriptionActive::class,   [$l, 'onSubscriptionActive']);
        Event::listen(SubscriptionPaid::class,     [$l, 'onSubscriptionPaid']);
        Event::listen(SubscriptionCanceled::class, [$l, 'onSubscriptionCanceled']);
        Event::listen(SubscriptionExpired::class,  [$l, 'onSubscriptionExpired']);
        Event::listen(SubscriptionPaused::class,   [$l, 'onSubscriptionPaused']);
        Event::listen(RefundCreated::class,        [$l, 'onRefundCreated']);
        Event::listen(DisputeCreated::class,       [$l, 'onDisputeCreated']);

        // Application-level events — constructor: ($customer, $metadata, $rawPayload)
        Event::listen(GrantAccess::class,  [$l, 'onGrantAccess']);
        Event::listen(RevokeAccess::class, [$l, 'onRevokeAccess']);
    }

    protected function registerLivewireComponents(): void
    {
        if (!class_exists(Livewire::class)) return;

        Livewire::component('creemdemo::configuration-form', \Modules\CreemDemo\Livewire\ConfigurationForm::class);
        Livewire::component('creemdemo::products-list',      \Modules\CreemDemo\Livewire\ProductsList::class);
        Livewire::component('creemdemo::subscriptions-list', \Modules\CreemDemo\Livewire\SubscriptionsList::class);
        Livewire::component('creemdemo::webhook-logs',       \Modules\CreemDemo\Livewire\WebhookLogs::class);
        Livewire::component('creemdemo::dashboard-stats',    \Modules\CreemDemo\Livewire\DashboardStats::class);
        Livewire::component('creemdemo::access-log',         \Modules\CreemDemo\Livewire\AccessLog::class);
        Livewire::component('creemdemo::discount-demo',      \Modules\CreemDemo\Livewire\DiscountDemo::class);
        Livewire::component('creemdemo::transactions-list',  \Modules\CreemDemo\Livewire\TransactionsList::class);
        Livewire::component('creemdemo::heartbeat',          \Modules\CreemDemo\Livewire\Heartbeat::class);
    }

    protected function registerConfig(): void
    {
        $configPath = module_path($this->moduleName, 'config/config.php');
        $this->publishes([$configPath => config_path($this->moduleNameLower . '.php')], 'config');
        $this->mergeConfigFrom($configPath, $this->moduleNameLower);
    }

    protected function registerViews(): void
    {
        $viewPath   = resource_path('views/modules/' . $this->moduleNameLower);
        $sourcePath = module_path($this->moduleName, 'resources/views');
        $this->publishes([$sourcePath => $viewPath], ['views', $this->moduleNameLower . '-module-views']);
        $this->loadViewsFrom(array_merge($this->getPublishableViewPaths(), [$sourcePath]), $this->moduleNameLower);
    }

    protected function registerTranslations(): void
    {
        $langPath = resource_path('lang/modules/' . $this->moduleNameLower);
        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $this->moduleNameLower);
            $this->loadJsonTranslationsFrom($langPath);
        } else {
            $this->loadTranslationsFrom(module_path($this->moduleName, 'resources/lang'), $this->moduleNameLower);
            $this->loadJsonTranslationsFrom(module_path($this->moduleName, 'resources/lang'));
        }
    }

    private function getPublishableViewPaths(): array
    {
        $paths = [];
        foreach ($this->app['config']->get('view.paths') as $path) {
            $p = $path . '/modules/' . $this->moduleNameLower;
            if (is_dir($p)) $paths[] = $p;
        }
        return $paths;
    }
}
