<?php

namespace Lara\Listeners;

use Exception;
use Lara\Events\ClubEventCreatedEvent;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Pusher\PushNotifications\PushNotifications;

class ClubEventCreatedListener
{
    /**
     * Handle the event.
     *
     * @param ClubEventCreatedEvent $event
     * @return void
     * @throws Exception
     */
    public function handle(ClubEventCreatedEvent $event)
    {
        $beamsClient = new PushNotifications(array(
            "instanceId" => config('broadcasting.connections.pusher.beams.instance_id'),
            "secretKey" => config('broadcasting.connections.pusher.beams.secret_key')
        ));

        $beamsClient->publishToInterests(
            array(str_replace("é", "e", $event->section->title)),
            array("fcm" => array("notification" => array(
                "title" => "A new event for {$event->section->title} was created!",
                "body" => "Title: {$event->newEvent->evnt_title} "
            )),
            )
        );
    }
}
