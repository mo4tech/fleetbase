<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Order status-change webhook
    |--------------------------------------------------------------------------
    |
    | Posts {order_id, previous_status, new_status, changed_at} to an external
    | endpoint whenever an order's status changes.
    |
    | Fleetbase's own webhooks cannot do this: their order.updated payload
    | carries only the NEW status, with no record of what it changed from.
    | The previous value is only knowable inside the model's update cycle,
    | which is why this runs as an observer rather than a native webhook.
    |
    | Set ORDER_STATUS_WEBHOOK_URL in api/.env to the receiving endpoint, then
    | recreate the containers with --force-recreate.
    |
    */

    'enabled' => env('ORDER_STATUS_WEBHOOK_ENABLED', true),

    'url' => env('ORDER_STATUS_WEBHOOK_URL'),

    /*
    | Optional shared secret. When set, each request carries an
    | X-Signature header: hash_hmac('sha256', <raw json body>, <secret>).
    | The receiver should recompute it over the raw body and compare.
    */
    'secret' => env('ORDER_STATUS_WEBHOOK_SECRET'),

    'timeout' => env('ORDER_STATUS_WEBHOOK_TIMEOUT', 10),

    /*
    | Statuses to skip. By default nothing is skipped - every transition is
    | reported. Comma-separated, e.g. "created,dispatched".
    */
    'ignore_statuses' => env('ORDER_STATUS_WEBHOOK_IGNORE'),
];
