<?php

namespace Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Udviklr\CashierNets\CashierNetsServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cashier-nets.secret_key' => 'test-secret-key',
            'cashier-nets.checkout_key' => 'test-checkout-key',
            'cashier-nets.sandbox' => true,
        ]);
    }

    protected function getPackageProviders($app): array
    {
        return [
            CashierNetsServiceProvider::class,
        ];
    }
}
