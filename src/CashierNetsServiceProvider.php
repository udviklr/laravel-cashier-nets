<?php

namespace Udviklr\CashierNets;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Udviklr\CashierNets\Client\NetsClient;
use Udviklr\CashierNets\Console\ChargeDueSubscriptionsCommand;
use Udviklr\CashierNets\Console\RetryPastDueSubscriptionsCommand;

class CashierNetsServiceProvider extends ServiceProvider
{
    /**
     * Register package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cashier-nets.php', 'cashier-nets');

        $this->app->singleton(NetsClient::class);
    }

    /**
     * Bootstrap package services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ChargeDueSubscriptionsCommand::class,
                RetryPastDueSubscriptionsCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/cashier-nets.php' => config_path('cashier-nets.php'),
            ], 'cashier-nets-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'cashier-nets-migrations');
        }

        if ($this->shouldRegisterRoutes()) {
            Route::middleware(config('cashier-nets.webhook_middleware', ['web']))
                ->prefix(config('cashier-nets.route_prefix', 'nets'))
                ->group(__DIR__.'/../routes/web.php');
        }
    }

    /**
     * Determine if the package routes should be registered.
     */
    protected function shouldRegisterRoutes(): bool
    {
        return CashierNets::$registersRoutes && (bool) config('cashier-nets.registers_routes', true);
    }
}
