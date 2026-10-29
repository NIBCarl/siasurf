<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message)
    {
        $this->message->loadMissing(['conversation', 'sender']);
    }

    /**
     * The private booking channel is shared by the student and instructor.
     * Channel authorization lives in routes/channels.php.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $conversation = $this->message->conversation;
        $booking = $conversation->booking;

        $channels = [
            new PrivateChannel('booking.' . $conversation->booking_id),
        ];

        if ($booking !== null) {
            // The recipient's personal channel drives the global toast and
            // unread badge even when the chat panel is closed. The booking
            // channel keeps an open ChatBox in sync.
            $recipientIsStudent = $conversation->student_id !== $this->message->sender_id;
            $recipientId = $recipientIsStudent
                ? $conversation->student_id
                : $conversation->instructor_id;

            $channels[] = new PrivateChannel(
                ($recipientIsStudent ? 'student.' : 'instructor.') . $recipientId
            );
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'chat.message';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'message' => [
                'id' => $this->message->id,
                'conversation_id' => $this->message->conversation_id,
                'sender_id' => $this->message->sender_id,
                'sender_name' => $this->message->sender?->name ?? 'SiaSurf',
                'body' => $this->message->body,
                'read_at' => $this->message->read_at?->toIso8601String(),
                'created_at' => $this->message->created_at?->toIso8601String(),
                'created_at_label' => $this->message->created_at?->format('M j, g:i A'),
            ],
        ];
    }
}
