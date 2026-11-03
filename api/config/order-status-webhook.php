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
    | NOT THE ACTIVE PATH. Order status now reaches the receiving system through
    | Fleetbase's own webhook (console -> Developers -> Webhooks, subscribed to
    | order.updated), because its payload already carries meta.ecom_type and
    | meta.source_id. See api/docs/order-status-webhook.md. This is disabled in
    | api/.env and kept for the two things the native webhook cannot do:
    |
    |   previous_status  The native order.updated payload carries only the NEW
    |                    status. The previous value is knowable only here,
    |                    inside the model's update cycle, via getOriginal().
    |
    |   replayable       A native delivery that exhausts its three attempts is
    |                    deleted rather than failed, so it never reaches
    |                    failed_jobs and webhooks:retry-failed cannot resend
    |                    it. This job can be replayed.
    |
    | Both paths can run at once: they are independent and neither reads the
    | other's config. Set ORDER_STATUS_WEBHOOK_ENABLED=true in api/.env and
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
