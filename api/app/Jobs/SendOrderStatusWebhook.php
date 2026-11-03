<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Delivers one order status-change notification to an external endpoint.
 *
 * Queued so a slow or unreachable receiver never delays the API request that
 * changed the order. Carries plain scalars rather than a model, so the payload
 * reflects the transition as it happened even if the order changes again
 * before this job runs.
 */
class SendOrderStatusWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Matches Fleetbase's own webhook retry count. */
    public $tries = 3;

    /** Seconds between attempts: 10s, then 60s, then 300s. */
    public array $backoff = [10, 60, 300];

    public function __construct(
        protected string $orderId,
        protected ?string $previousStatus,
        protected string $newStatus,
        protected string $changedAt,
        protected ?string $internalId = null,
    ) {
    }

    public function handle(): void
    {
        $url = config('order-status-webhook.url');

        if (!$url) {
            Log::warning('Order status webhook skipped: ORDER_STATUS_WEBHOOK_URL is not set', [
                'order' => $this->orderId,
            ]);

            return;
        }

        $payload = [
            'order_id'        => $this->orderId,
            'internal_id'     => $this->internalId,
            'previous_status' => $this->previousStatus,
            'new_status'      => $this->newStatus,
            'changed_at'      => $this->changedAt,
        ];

        // Sign the exact bytes sent, so the receiver can verify by recomputing
        // over its raw request body. Re-encoding either side would break it.
        $body    = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = ['Content-Type' => 'application/json'];

        if ($secret = config('order-status-webhook.secret')) {
            $headers['X-Signature'] = hash_hmac('sha256', $body, $secret);
        }

        $response = Http::withHeaders($headers)
            ->timeout((int) config('order-status-webhook.timeout', 10))
            ->withBody($body, 'application/json')
            ->post($url);

        if ($response->failed()) {
            Log::warning('Order status webhook rejected by receiver', [
                'order'    => $this->orderId,
                'status'   => $response->status(),
                'attempt'  => $this->attempts(),
                'response' => substr((string) $response->body(), 0, 200),
            ]);

            // Throwing hands the job back to the queue so $backoff applies.
            $response->throw();
        }

        Log::info('Order status webhook delivered', [
            'order' => $this->orderId,
            'from'  => $this->previousStatus,
            'to'    => $this->newStatus,
        ]);
    }
}
