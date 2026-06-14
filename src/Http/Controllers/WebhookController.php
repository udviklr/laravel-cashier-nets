<?php

namespace Udviklr\CashierNets\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Udviklr\CashierNets\CashierNets;
use Udviklr\CashierNets\Events\ChargeFailed;
use Udviklr\CashierNets\Events\ChargeSucceeded;
use Udviklr\CashierNets\Events\CheckoutCompleted;
use Udviklr\CashierNets\Events\RefundCompleted;
use Udviklr\CashierNets\Events\RefundFailed;
use Udviklr\CashierNets\Events\RefundInitiated;
use Udviklr\CashierNets\Events\WebhookHandled;
use Udviklr\CashierNets\Events\WebhookReceived;
use Udviklr\CashierNets\WebhookEvent;
use Udviklr\CashierNets\Webhooks\WebhookHandlingResult;
use Udviklr\CashierNets\Webhooks\WebhookHandler;
use Udviklr\CashierNets\Webhooks\WebhookPayload;

class WebhookController extends Controller
{
    /**
     * Handle an incoming Nets webhook.
     */
    public function __invoke(Request $request, WebhookHandler $handler)
    {
        $this->authorizeWebhook($request);

        $payload = $request->all();
        $eventModel = CashierNets::$webhookEventModel;
        $eventId = $handler->eventId($payload);
        $eventName = $handler->eventName($payload);

        event(new WebhookReceived($payload));

        if ($eventId === null) {
            $event = new $eventModel;

            $event->forceFill([
                'nets_event_id' => null,
                'event_name' => $eventName,
                'payload' => $payload,
            ])->save();

            return DB::transaction(fn () => $this->process($event, $handler, $payload));
        }

        // Claim the event row before the processing transaction so a failed
        // delivery leaves an unprocessed row behind for Nets redelivery.
        $event = $eventModel::query()->createOrFirst([
            'nets_event_id' => $eventId,
        ], [
            'event_name' => $eventName,
            'payload' => $payload,
        ]);

        return DB::transaction(function () use ($event, $handler, $payload) {
            $query = $event->newQuery();
            $query->lockForUpdate();

            $event = $query->findOrFail($event->getKey());

            if ($event->processed()) {
                event(new WebhookHandled($payload));

                return response()->json(['received' => true, 'duplicate' => true]);
            }

            return $this->process($event, $handler, $payload);
        });
    }

    /**
     * Process a claimed webhook event.
     *
     * The typed event is dispatched before the row is marked processed, so a
     * consumer listener exception leaves the event unprocessed and the next
     * Nets redelivery re-runs the handler and listeners.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function process(WebhookEvent $event, WebhookHandler $handler, array $payload)
    {
        $result = $handler->handle($payload);

        $this->dispatchTypedWebhookEvent($result, $event);

        $event->forceFill([
            'processed_at' => now(),
        ])->save();

        event(new WebhookHandled($payload));

        return response()->json(['received' => true]);
    }

    /**
     * Authorize the incoming Nets webhook request.
     */
    protected function authorizeWebhook(Request $request): void
    {
        $expected = (string) config('cashier-nets.webhook_authorization', '');

        if ($expected === '') {
            if ($this->webhookAuthorizationRequired()) {
                Log::critical('Cashier Nets rejected a webhook because no webhook authorization secret is configured. Set NETS_WEBHOOK_SECRET to restore webhook processing.');

                abort(Response::HTTP_SERVICE_UNAVAILABLE, 'Webhook authorization is not configured.');
            }

            return;
        }

        $actual = (string) $request->header('Authorization', '');

        abort_unless(hash_equals($expected, $actual), Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Determine if a configured webhook authorization secret is required.
     */
    protected function webhookAuthorizationRequired(): bool
    {
        $required = config('cashier-nets.webhook_authorization_required');

        if ($required !== null) {
            return filter_var($required, FILTER_VALIDATE_BOOL);
        }

        return app()->environment('production');
    }

    /**
     * Dispatch a semantic webhook event when the Nets event maps to one.
     */
    protected function dispatchTypedWebhookEvent(WebhookHandlingResult $result, WebhookEvent $webhookEvent): void
    {
        $class = $this->typedWebhookEventClass($result->payload);

        if ($class === null) {
            return;
        }

        event(new $class($result->payload, $webhookEvent, $result->subscription, $result->transaction));
    }

    /**
     * Get the semantic event class for a parsed webhook payload.
     *
     * @return class-string|null
     */
    protected function typedWebhookEventClass(WebhookPayload $payload): ?string
    {
        return match ($payload->eventName()) {
            'payment.checkout.completed' => CheckoutCompleted::class,
            'payment.charge.created', 'payment.charge.created.v2' => ChargeSucceeded::class,
            'payment.charge.failed', 'payment.charge.failed.v2', 'payment.reservation.failed' => ChargeFailed::class,
            'payment.refund.initiated' => RefundInitiated::class,
            'payment.refund.completed' => RefundCompleted::class,
            'payment.refund.failed' => RefundFailed::class,
            default => null,
        };
    }
}
