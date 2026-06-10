<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Udviklr\CashierNets\CashierNets;

class PackageBootTest extends TestCase
{
    public function test_it_merges_the_package_configuration(): void
    {
        $this->assertTrue(config('cashier-nets.sandbox'));
        $this->assertSame('https://test.api.dibspayment.eu', config('cashier-nets.api_urls.sandbox'));
    }

    public function test_it_registers_the_webhook_route(): void
    {
        $route = Route::getRoutes()->getByName('cashier-nets.webhook');

        $this->assertNotNull($route);
        $this->assertSame('nets/webhook', $route->uri());
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame(['web'], $route->middleware());
    }

    public function test_it_resolves_the_sandbox_urls(): void
    {
        $this->assertSame('https://test.api.dibspayment.eu', CashierNets::apiUrl());
        $this->assertSame('https://test.checkout.dibspayment.eu/v1/checkout.js?v=1', CashierNets::checkoutJsUrl());
    }
}
