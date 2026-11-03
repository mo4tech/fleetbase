<?php

namespace App\Providers;

use App\Observers\OrderDestinationObserver;
use App\Observers\OrderStatusWebhookObserver;
use Fleetbase\FleetOps\Models\Order;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        // Advance a simple order's destination from pickup to dropoff once the
        // driver collects the goods. Fleetbase only does this for multi-drop
        // orders. See App\Observers\OrderDestinationObserver.
        if (class_exists(Order::class)) {
            Order::observe(OrderDestinationObserver::class);

            // Reports status transitions to an external endpoint, including the
            // PREVIOUS status - which Fleetbase's own webhooks cannot provide.
            Order::observe(OrderStatusWebhookObserver::class);
        }
    }
}
