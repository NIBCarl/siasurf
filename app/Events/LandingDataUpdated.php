<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class LandingDataUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $section;

    public string $updatedAt;

    public function __construct(string $section, ?string $updatedAt = null)
    {
        $this->section = $section;
        $this->updatedAt = $updatedAt ?? now()->toIso8601String();
    }

    /**
     * Broadcast on a public channel so anonymous landing visitors receive
     * live refresh nudges without authentication.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('landing.public'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'landing.updated';
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return [
            'section' => $this->section,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * Notify public landing listeners after database writes without ever
     * failing the underlying request if Reverb is offline or unconfigured.
     */
    public static function notify(string $section): void
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return;
        }

        $dispatch = static function () use ($section): void {
            try {
                event(new self($section));
            } catch (\Throwable) {
                // An offline or unconfigured socket must never break writes.
            }
        };

        if (app()->runningUnitTests()) {
            $dispatch();

            return;
        }

        DB::afterCommit($dispatch);
    }
}
