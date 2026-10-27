<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Post-pickup statuses
    |--------------------------------------------------------------------------
    |
    | Comma-separated order status codes that mean the driver has collected the
    | goods and is now heading to the customer. When an order reaches one of
    | these, App\Observers\OrderDestinationObserver points the payload's current
    | waypoint at the dropoff.
    |
    | Leave unset to use the observer's defaults:
    |   order_picked_up, arrived_at_customer, order_delivered, completed
    |
    | Override only if your order config uses different activity codes.
    |
    */

    'post_pickup_statuses' => env('ORDER_POST_PICKUP_STATUSES'),
];
