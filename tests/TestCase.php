<?php

namespace Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Udviklr\CashierNets\CashierNetsServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            CashierNetsServiceProvider::class,
        ];
    }
}
