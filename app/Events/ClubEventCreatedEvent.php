<?php

namespace Lara\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Lara\Section;

class ClubEventCreatedEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $newEvent;
    public $section;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct($newEvent)
    {
        $this->newEvent = $newEvent;
        $this->section = Section::where('id', '=', $newEvent->plc_id)->first();
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new PrivateChannel('channel-name');
    }
}
