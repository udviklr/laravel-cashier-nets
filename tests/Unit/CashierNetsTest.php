<?php

namespace Tests\Unit;

use Tests\TestCase;
use Udviklr\CashierNets\CashierNets;

class CashierNetsTest extends TestCase
{
    public function test_it_exposes_the_package_version(): void
    {
        $this->assertSame('0.1.0', CashierNets::VERSION);
    }

    public function test_it_exposes_the_configured_checkout_key(): void
    {
        config(['cashier-nets.checkout_key' => 'checkout-key-123']);

        $this->assertSame('checkout-key-123', CashierNets::checkoutKey());
    }
}
