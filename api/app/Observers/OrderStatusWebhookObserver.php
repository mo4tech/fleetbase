<?php

namespace App\Observers;

use App\Jobs\SendOrderStatusWebhook;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Carbon;

/**
 * Reports every order status transition to an external endpoint.
 *
 * Fleetbase's built-in webhooks send only the new status - their order.updated
 * payload has no record of what the status changed from. The previous value
 * exists only here, inside the model's update cycle, via getOriginal().
 */
class OrderStatusWebhookObserver
{
    public function updated(Order $order): void
    {
        if (!config('order-status-webhook.enabled', true)) {
            return;
        }

        if (!$order->wasChanged('status')) {
            return;
        }

        $previous = $order->getOriginal('status');
        $new      = $order->status;

        // A save that rewrites the same value is not a transition.
        if ($previous === $new) {
            return;
        }

        if (in_array($new, $this->ignoredStatuses(), true)) {
            return;
        }

        SendOrderStatusWebhook::dispatch(
            $order->public_id,
            $previous,
            $new,
            Carbon::now()->toIso8601String(),
            $order->internal_id,
        );
    }

    protected function ignoredStatuses(): array
    {
        $configured = config('order-status-webhook.ignore_statuses');

        if (!is_string($configured) || trim($configured) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $configured))));
    }
}
