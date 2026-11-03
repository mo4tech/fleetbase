<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off repair for orders whose destination never advanced past the pickup.
 *
 * Fleetbase only advances `payload.current_waypoint_uuid` for multi-drop
 * orders, so every simple pickup -> dropoff order ever created still points at
 * its pickup. App\Observers\OrderDestinationObserver fixes this going forward;
 * this command repairs the existing backlog.
 *
 * Defaults to a dry run. Pass --apply to write.
 */
class BackfillOrderDestination extends Command
{
    protected $signature = 'orders:backfill-destination
                            {--scope=active : "active" (in-flight orders only) or "all" (includes completed history)}
                            {--apply : Write the changes. Without this the command only reports.}
                            {--backup= : Path to write a JSON rollback file (default: storage/app/)}';

    protected $description = 'Point past-pickup orders at their dropoff instead of their pickup';

    /**
     * Orders a driver could still be navigating. Completed orders are inert -
     * their destination field changes nothing operationally.
     */
    protected array $activeStatuses = ['order_picked_up', 'arrived_at_customer'];

    protected array $allStatuses = ['order_picked_up', 'arrived_at_customer', 'order_delivered', 'completed'];

    public function handle(): int
    {
        $scope = $this->option('scope');

        if (!in_array($scope, ['active', 'all'], true)) {
            $this->error('--scope must be "active" or "all"');

            return self::FAILURE;
        }

        $statuses = $scope === 'all' ? $this->allStatuses : $this->activeStatuses;
        $targets  = $this->findTargets($statuses);

        if ($targets->isEmpty()) {
            $this->info('Nothing to backfill.');

            return self::SUCCESS;
        }

        $this->line('Scope: <comment>' . $scope . '</comment> (' . implode(', ', $statuses) . ')');
        $this->newLine();

        $byStatus = $targets->groupBy('status')->map->count();
        foreach ($byStatus as $status => $count) {
            $this->line(sprintf('  %-22s %4d', $status, $count));
        }
        $this->line(sprintf('  %-22s %4d', 'TOTAL', $targets->count()));
        $this->newLine();

        if (!$this->option('apply')) {
            $this->warn('Dry run - nothing written. Re-run with --apply to commit.');

            return self::SUCCESS;
        }

        $backupPath = $this->writeBackup($targets);
        $this->info('Rollback file: ' . $backupPath);

        $updated = 0;
        DB::transaction(function () use ($targets, &$updated) {
            foreach ($targets as $t) {
                $updated += DB::table('payloads')
                    ->where('uuid', $t->payload_uuid)
                    // Guard against a concurrent write between read and update.
                    ->where('current_waypoint_uuid', $t->current_waypoint_uuid)
                    ->update([
                        'current_waypoint_uuid' => $t->dropoff_uuid,
                        'updated_at'            => now(),
                    ]);
            }
        });

        $this->info('Updated ' . $updated . ' of ' . $targets->count() . ' payloads.');

        if ($updated !== $targets->count()) {
            $this->warn('Some rows changed underneath the command and were skipped. Re-run to pick them up.');
        }

        return self::SUCCESS;
    }

    /**
     * Simple orders, past pickup, still pointing somewhere other than the
     * dropoff. Payloads carrying waypoints belong to the vendor's multi-drop
     * logic and are excluded.
     */
    protected function findTargets(array $statuses)
    {
        return DB::table('orders as o')
            ->join('payloads as p', 'p.uuid', '=', 'o.payload_uuid')
            ->whereNull('o.deleted_at')
            ->whereIn('o.status', $statuses)
            ->whereNotNull('p.pickup_uuid')
            ->whereNotNull('p.dropoff_uuid')
            ->whereColumn('p.current_waypoint_uuid', '!=', 'p.dropoff_uuid')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('waypoints as w')
                  ->whereColumn('w.payload_uuid', 'p.uuid');
            })
            ->select(
                'o.public_id as order_public_id',
                'o.status',
                'p.uuid as payload_uuid',
                'p.current_waypoint_uuid',
                'p.dropoff_uuid'
            )
            ->orderBy('o.created_at')
            ->get();
    }

    protected function writeBackup($targets): string
    {
        $path = $this->option('backup')
            ?: storage_path('app/backfill-destination-' . now()->format('Ymd-His') . '.json');

        $rows = $targets->map(fn ($t) => [
            'order'                 => $t->order_public_id,
            'payload_uuid'          => $t->payload_uuid,
            'current_waypoint_uuid' => $t->current_waypoint_uuid,
        ])->values();

        file_put_contents($path, json_encode($rows, JSON_PRETTY_PRINT));

        return $path;
    }
}
