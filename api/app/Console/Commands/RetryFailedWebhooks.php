<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Re-sends order status webhooks that exhausted their automatic retries.
 *
 * SendOrderStatusWebhook retries three times over about 75 seconds. If the
 * receiving endpoint is down longer than that, the job is abandoned and lands
 * in failed_jobs with its full payload intact. This command finds those and
 * puts them back on the queue, so a longer outage costs nothing once the
 * endpoint returns.
 *
 * Scheduled every ten minutes in App\Console\Kernel.
 *
 * Scope note: this handles OUR status webhook only. Fleetbase's own webhooks
 * log their failures to webhook_request_logs, but those cannot be replayed
 * faithfully - each delivery is signed with the API secret from the original
 * request's session, which is never stored, so a replay would carry a
 * signature the receiver cannot verify. Use --report-native to see those
 * failures without attempting to resend them.
 */
class RetryFailedWebhooks extends Command
{
    protected $signature = 'webhooks:retry-failed
                            {--minutes=60 : Only retry failures from the last N minutes}
                            {--max=50 : Stop after this many, so one bad endpoint cannot flood the queue}
                            {--dry-run : Report what would be retried without doing it}
                            {--report-native : Also report Fleetbase native webhook failures}';

    protected $description = 'Re-queue order status webhooks whose automatic retries were exhausted';

    public function handle(): int
    {
        $minutes = (int) $this->option('minutes');
        $max     = (int) $this->option('max');
        $dryRun  = (bool) $this->option('dry-run');

        $failed = DB::table('failed_jobs')
            ->where('payload', 'like', '%SendOrderStatusWebhook%')
            ->where('failed_at', '>=', now()->subMinutes($minutes))
            ->orderBy('failed_at')
            ->limit($max)
            ->get();

        if ($failed->isEmpty()) {
            $this->info("No failed status webhooks in the last {$minutes} minutes.");
        } else {
            $this->line("Found {$failed->count()} failed status webhook(s):");
            $this->newLine();

            foreach ($failed as $job) {
                $detail = $this->describe($job);
                $this->line(sprintf('  %s  %s', $job->failed_at, $detail));
            }
            $this->newLine();

            if ($dryRun) {
                $this->warn('Dry run - nothing re-queued. Drop --dry-run to retry.');
            } else {
                $retried = 0;
                foreach ($failed as $job) {
                    // queue:retry removes the row and pushes the job back with
                    // its original payload, so the receiver gets exactly what
                    // it would have had, correctly signed.
                    Artisan::call('queue:retry', ['id' => [$job->uuid]]);
                    $retried++;

                    Log::info('Re-queued failed order status webhook', [
                        'job_uuid'  => $job->uuid,
                        'failed_at' => $job->failed_at,
                    ]);
                }

                $this->info("Re-queued {$retried} webhook(s).");
            }
        }

        if ($this->option('report-native')) {
            $this->reportNative($minutes);
        }

        return self::SUCCESS;
    }

    /**
     * Pull the order and transition out of the serialised job, so the operator
     * can see what is being resent rather than an opaque job id.
     */
    protected function describe(object $job): string
    {
        try {
            $payload = json_decode($job->payload, true);
            $command = unserialize($payload['data']['command']);
            $ref     = new \ReflectionClass($command);

            $read = function (string $prop) use ($ref, $command) {
                $p = $ref->getProperty($prop);
                $p->setAccessible(true);

                return $p->getValue($command);
            };

            return sprintf(
                '%s  %s -> %s',
                $read('orderId'),
                $read('previousStatus') ?? 'null',
                $read('newStatus')
            );
        } catch (\Throwable $e) {
            return '(could not read payload: ' . $e->getMessage() . ')';
        }
    }

    /**
     * Fleetbase's own webhooks: report only. An event counts as failed when
     * none of its attempts succeeded.
     */
    protected function reportNative(int $minutes): void
    {
        $this->newLine();
        $this->line('Fleetbase native webhook failures (report only):');

        $rows = DB::table('webhook_request_logs')
            ->select('api_event_uuid', 'webhook_uuid')
            ->selectRaw('count(*) attempts, max(created_at) last_attempt')
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->groupBy('api_event_uuid', 'webhook_uuid')
            ->havingRaw('sum(status = "successful") = 0')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('  None.');

            return;
        }

        foreach ($rows as $r) {
            $event = DB::table('api_events')->where('uuid', $r->api_event_uuid)->value('event');
            $this->line(sprintf(
                '  %s  %s  (%d attempts, all failed)',
                $r->last_attempt,
                $event ?? $r->api_event_uuid,
                $r->attempts
            ));
        }

        $this->newLine();
        $this->warn('  These are not resent: Fleetbase signs each delivery with the API');
        $this->warn('  secret from the original request, which is not stored, so a replay');
        $this->warn('  would fail the receiver\'s signature check. Resend from the console');
        $this->warn('  (Developers -> Webhooks -> Attempts) if one genuinely matters.');
    }
}
