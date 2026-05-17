<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\Concerns\WithLaravelMigrations;
use Tests\TestCase;
use Udviklr\CashierNets\WebhookEvent;

class WebhookEventModelTest extends TestCase
{
    use RefreshDatabase;
    use WithLaravelMigrations;

    public function test_it_casts_payload_and_processed_at(): void
    {
        $event = WebhookEvent::create([
            'nets_event_id' => 'evt_123',
            'event_name' => 'payment.created',
            'payload' => ['eventName' => 'payment.created'],
            'processed_at' => Carbon::now(),
        ]);

        $this->assertSame('payment.created', $event->payload['eventName']);
        $this->assertTrue($event->processed());
    }
}
