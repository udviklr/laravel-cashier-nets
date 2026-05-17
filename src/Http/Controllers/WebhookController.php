<?php

namespace Udviklr\CashierNets\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;
use Udviklr\CashierNets\CashierNets;
use Udviklr\CashierNets\Events\ChargeFailed;
use Udviklr\CashierNets\Events\ChargeSucceeded;
use Udviklr\CashierNets\Events\CheckoutCompleted;
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

        if ($eventId !== null) {
            $event = $eventModel::query()
                ->where('nets_event_id', $eventId)
                ->first();

            if ($event && $event->processed()) {
                event(new WebhookHandled($payload));

                return response()->json(['received' => true, 'duplicate' => true]);
            }
        }

        $event ??= new $eventModel;

        $event->forceFill([
            'nets_event_id' => $eventId,
            'event_name' => $eventName,
            'payload' => $payload,
        ])->save();

        $result = $handler->handle($payload);

        $event->forceFill([
            'processed_at' => now(),
        ])->save();

        $this->dispatchTypedWebhookEvent($result, $event);

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
            return;
        }

        $actual = (string) $request->header('Authorization', '');

        abort_unless(hash_equals($expected, $actual), Response::HTTP_UNAUTHORIZED);
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
            default => null,
        };
    }
}
