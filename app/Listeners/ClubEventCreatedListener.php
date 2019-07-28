<?php

namespace Lara\Listeners;

use Lara\Events\ClubEventCreatedEvent;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class ClubEventCreatedListener
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  ClubEventCreatedEvent  $event
     * @return void
     */
    public function handle(ClubEventCreatedEvent $event)
    {
        //
    }
}
