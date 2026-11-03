# Order status webhook

How order status reaches the Botit backend, and what the receiving endpoint has
to handle.

## What sends it

Fleetbase's own webhook, not custom code. It is configured in the console under
**Developers → Webhooks**, and its payload already carries the two fields the
backend needs to tell an order's two legs apart:

- `data.meta.ecom_type` — `inbound` (vendor → hub) or `outbound` (hub → customer)
- `data.meta.source_id` — the order's id in the Botit system

Nothing in `api/app/` is involved, and nothing under `api/vendor/fleetbase/`
was modified.

## The endpoint

| | |
|---|---|
| `webhook_endpoints.uuid` | `4bf45c00-890f-4b59-a6c7-033716639665` |
| url | `http://webhook-flask:5000/webhook` (local test receiver) |
| mode | `live` |
| events | `order.updated` |
| status | `enabled` |

Point the url at the Botit backend from the console when its path is known.
There is no need to touch the server for that.

### Why only `order.updated`

The botit flow uses custom statuses (`arrived_at_branch`, `order_picked_up`,
`arrived_at_customer`, `order_delivered`). Fleetbase has named events for a few
transitions only — `order.dispatched`, `order.completed`, `order.canceled`,
`order.started`, `order.ready`, `order.failed`, `order.driver_assigned` — and
none for the custom ones. `order.updated` fires for *every* status, the custom
ones included, and also fires alongside each named event. Subscribing to
anything else therefore adds duplicates without adding coverage.

Traced end to end on a real order, `order.updated` delivered: `dispatched` →
`started` → `arrived_at_branch` → `order_picked_up` → `arrived_at_customer` →
`completed`.

## Payload

```json
{
  "id": "event_6ae9b0ee84eaa",
  "api_version": "2024-03-12",
  "event": "order.updated",
  "created_at": "2026-11-03 10:01:18",
  "data": {
    "id": "order_xnTuUc8",
    "internal_id": "39080",
    "status": "order_picked_up",
    "type": "botit",
    "adhoc": false,
    "notes": "Vendor: Aphrodite Accessories\nPayment: Prepaid",
    "customer": "contact_7JCG5WS",
    "payload": "payload_izEXdVS",
    "facilitator": null,
    "driver_assigned": "driver_0daSjk7",
    "tracking_number": "track_puBZObv",
    "purchase_rate": null,
    "meta": {
      "area": "cairo",
      "cash": "930 EGP",
      "source": "FourPL",
      "ecom_type": "outbound",
      "source_id": "6ae65e190262293e19c9bc99",
      "sort_index": 0,
      "integration": "shopify",
      "paymentMethod": "Card"
    },
    "scheduled_at": "2026-11-01 14:15:49",
    "dispatched_at": "2026-11-01 14:15:51",
    "started_at": "2026-11-01 16:33:50",
    "created_at": "2026-11-01 14:15:49",
    "updated_at": "2026-11-03 10:00:05"
  }
}
```

`type` is the order configuration, i.e. which status flow the order follows. It
is `botit` on every order here, so it cannot be used to tell the legs apart —
that is what `meta.ecom_type` is for.

## What the receiver must handle

**1. Duplicates and no-ops.** `order.updated` fires on any change to the order
row, not only a status change, and fires more than once for the same status.
One real order produced four deliveries all reading `dispatched` within the same
second of being created. Keep the last status you applied per order and ignore a
delivery that does not change it.

**2. No `previous_status`.** The payload carries only the new status. If the
backend needs the transition rather than the state, it has to remember the
previous value itself.

**3. A missing `ecom_type` is not `outbound`.** About 5% of orders arrive
without it — 25 of the 556 created in the seven days to 3 Nov 2026, against
`source_id` at 556 of 556. Treat those as unknown and do not notify the
customer, rather than assuming the customer leg — otherwise a delivery to the
warehouse is announced as a delivery to the customer.

**4. `completed` is ambiguous on its own.** Both legs end in `completed` with
identical status codes; `meta.ecom_type` is the only thing that distinguishes
"arrived at our hub" from "arrived at the customer". One source order can
produce three legs.

**5. Order by the payload, not by arrival.** Each delivery is an independent
queued job. Use `data.updated_at` or the top-level `created_at` to decide which
of two deliveries is newer.

## Signature

Each delivery carries a `Signature` header:
`hash_hmac('sha256', <raw request body>, <secret>)`.

The secret is **not** configurable per endpoint, and it is not stable. Fleetbase
signs with `session('api_secret')`, falling back to the literal string
`internal` (`Listeners/SendResourceLifecycleWebhook.php`). Two things make that
value unpredictable:

- Which value exists depends on how the change was made. A request through the
  `v1` API — the driver app and the integration both — ends up with the
  company's first API credential secret, because `authenticateSanctumToken`
  resolves one and calls `Auth::setApiKey`. A change made in the console, from
  the scheduler, or from the CLI has no `api_secret` at all, so it falls back
  to `internal`.
- The listener runs on the queue, and `queue:work` does not reset the session
  between jobs. `setSessionFromEvent` only writes a value that is not already
  there, so whichever value the worker sees first is reused by every delivery
  until the worker restarts.

Every delivery observed on this deployment verified against `internal`,
including ones triggered from the driver app — the worker had latched
`internal` first. That is luck, not design: restart the queue container in a
different order of traffic and the same endpoint starts signing with a
different secret.

Do not treat the header as authentication. `internal` is a constant in
Fleetbase's source, so when that is the latched value anyone can produce a
valid signature. Protect the receiving endpoint another way — restrict it to
this server's address, or give it its own auth and have the backend ignore the
header.

The custom observer, by contrast, signs with a secret we choose
(`ORDER_STATUS_WEBHOOK_SECRET`, sent as `X-Signature`).

## Retries

Three attempts, 10s then 100s apart. After the third, `CallWebhookJob` calls
`delete()` rather than `fail()` — so the delivery never reaches `failed_jobs`
and **cannot be replayed**. Attempts are visible in the console under
Developers → Webhooks → Attempts, and in `webhook_request_logs`.

`php artisan webhooks:retry-failed --report-native` lists native failures. It
cannot resend them.

## The custom webhook

`api/app/Observers/OrderStatusWebhookObserver.php` and
`api/app/Jobs/SendOrderStatusWebhook.php` still exist, switched off with
`ORDER_STATUS_WEBHOOK_ENABLED=false` in `api/.env`. They send a flat
`{order_id, internal_id, previous_status, new_status, changed_at}` and are the
only path that provides `previous_status` and that can be replayed after a long
receiver outage. Set the flag back to `true` and recreate the containers to run
both at once; they are independent.
