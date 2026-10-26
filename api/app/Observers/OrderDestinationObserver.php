<?php

namespace App\Observers;

use Fleetbase\FleetOps\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Advances a simple order's current destination from the pickup to the dropoff
 * once the driver has collected the goods.
 *
 * Fleetbase only advances `payload.current_waypoint_uuid` for multi-drop orders.
 * `Payload::getIsMultipleDropOrderAttribute()` is true only when a payload has
 * NO pickup and at least one waypoint, so an ordinary pickup -> dropoff order
 * never qualifies and `setNextWaypointDestination()` is never reached. The
 * destination is set to the pickup when the order starts and then stays there
 * for the rest of the order's life, which leaves the driver app navigating back
 * to the pickup after collection.
 *
 * This observer fills that gap for simple orders only. Multi-drop orders are
 * skipped so the vendor's waypoint machinery stays in sole control of them.
 */
class OrderDestinationObserver
{
    /**
     * Order statuses that mean the goods are collected and the driver is now
     * heading to the customer.
     *
     * Later statuses are included deliberately: if a pickup scan is missed or
     * arrives out of order, the destination still gets corrected rather than
     * being stranded at the pickup for the rest of the order.
     */
    protected function postPickupStatuses(): array
    {
        $configured = config('fleetops-destination.post_pickup_statuses');

        if (is_string($configured) && trim($configured) !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $configured))));
        }

        return ['order_picked_up', 'arrived_at_customer', 'order_delivered', 'completed'];
    }

    /**
     * Handle the Order "updated" event.
     */
    public function updated(Order $order): void
    {
        if (!$order->wasChanged('status')) {
            return;
        }

        if (!in_array($order->status, $this->postPickupStatuses(), true)) {
            return;
        }

        $this->advanceToDropoff($order);
    }

    /**
     * Point the payload's current waypoint at the dropoff, if this is a simple
     * order that still points at its pickup.
     *
     * Written against the query builder rather than the Payload model so that
     * saving cannot cascade into other observers or re-trigger this one.
     */
    protected function advanceToDropoff(Order $order): void
    {
        if (!$order->payload_uuid) {
            return;
        }

        $payload = DB::table('payloads')->where('uuid', $order->payload_uuid)->first();

        if (!$payload || !$payload->pickup_uuid || !$payload->dropoff_uuid) {
            return;
        }

        // Already pointing at the dropoff - nothing to do. Keeps this idempotent
        // across the several status changes that follow a pickup.
        if ($payload->current_waypoint_uuid === $payload->dropoff_uuid) {
            return;
        }

        // Multi-drop orders are the vendor's to manage. A payload carrying
        // waypoints is never a simple order, so leave it alone.
        $hasWaypoints = DB::table('waypoints')->where('payload_uuid', $payload->uuid)->exists();

        if ($hasWaypoints) {
            return;
        }

        DB::table('payloads')
            ->where('uuid', $payload->uuid)
            ->update([
                'current_waypoint_uuid' => $payload->dropoff_uuid,
                'updated_at'            => now(),
            ]);

        Log::info('Advanced order destination to dropoff', [
            'order'    => $order->public_id,
            'status'   => $order->status,
            'payload'  => $payload->public_id,
            'was'      => $payload->current_waypoint_uuid,
            'now'      => $payload->dropoff_uuid,
        ]);
    }
}
