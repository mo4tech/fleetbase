<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Order status webhooks give up after ~75 seconds of a receiver being
        // unreachable. This sweeps up anything abandoned and puts it back on
        // the queue, so a longer outage self-heals once the endpoint returns.
        $schedule->command('webhooks:retry-failed --minutes=60')
                 ->everyTenMinutes()
                 ->withoutOverlapping();

        // $schedule->command('inspire')->hourly();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');
    }
}
