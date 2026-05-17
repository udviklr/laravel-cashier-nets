<?php

use Illuminate\Support\Facades\Route;
use Udviklr\CashierNets\Http\Controllers\WebhookController;

Route::post(config('cashier-nets.webhook_path', 'webhook'), WebhookController::class)
    ->name('cashier-nets.webhook');
