<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class WebhookMiddlewareConfigTest extends TestCase
{
    /**
     * Define the environment before the package routes are registered.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cashier-nets.webhook_middleware', ['api', 'throttle:60,1']);
    }

    public function test_the_webhook_route_uses_the_configured_middleware(): void
    {
        $route = Route::getRoutes()->getByName('cashier-nets.webhook');

        $this->assertNotNull($route);
        $this->assertSame(['api', 'throttle:60,1'], $route->middleware());
    }
}
