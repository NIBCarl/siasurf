<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Events\MessageSent;
use App\Models\Booking;
use App\Models\BookingChangeRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConversationService
{
    /**
     * Return the booking's thread, creating it the first time the booking is
     * confirmed (or when a reschedule discussion opens). Existing threads are
     * returned even after cancellation so the participants keep their history.
     */
    public function ensureForBooking(Booking $booking, bool $forceCreate = false): ?Conversation
    {
        $existing = $booking->conversation()->first();

        if ($existing) {
            return $existing;
        }

        if (! $forceCreate
            && ! $booking->hasOpenRescheduleProposal()
            && ! in_array($booking->status, [
                BookingStatus::Confirmed,
                BookingStatus::InProgress,
                BookingStatus::Completed,
            ], true)
        ) {
            return null;
        }

        return Conversation::create([
            'booking_id' => $booking->id,
            'student_id' => $booking->student_id,
            'instructor_id' => $booking->instructor_id,
        ]);
    }

    /**
     * Post the instructor's reschedule proposal and reason into the booking chat
     * thread so the instructor and student can discuss before agreeing.
     */
    public function sendRescheduleReasonMessage(
        Booking $booking,
        User $sender,
        BookingChangeRequest $changeRequest,
    ): ?Message {
        $conversation = $this->ensureForBooking($booking, true);

        if (! $conversation) {
            return null;
        }

        $oldDate = $changeRequest->original_date?->format('M d, Y') ?? 'original date';
        $oldSlot = $changeRequest->describeTimeRange(
            $changeRequest->original_start_time,
            $changeRequest->original_end_time,
            $changeRequest->original_time_period
        );
        $newDate = $changeRequest->new_date?->format('M d, Y') ?? 'new date';
        $newSlot = $changeRequest->describeTimeRange(
            $changeRequest->new_start_time,
            $changeRequest->new_end_time,
            $changeRequest->new_time_period
        );

        $lines = [
            "Reschedule Request: I would like to move our surf lesson from {$oldDate} ({$oldSlot}) to {$newDate} ({$newSlot}).",
        ];

        $reason = trim((string) $changeRequest->reason);
        if ($reason !== '') {
            $lines[] = "Reason: {$reason}";
        }

        $lines[] = 'Please reply here if you have any questions, or use the Reschedule banner on your booking page to agree or decline.';

        $message = $conversation->messages()->create([
            'sender_id' => $sender->id,
            'body' => implode("\n", $lines),
        ]);

        $message->load('sender:id,name');
        $this->touchLastMessage($conversation, $message);

        DB::afterCommit(function () use ($message, $booking) {
            try {
                broadcast(new MessageSent($message->fresh(['sender', 'conversation'])))->toOthers();
            } catch (\Throwable $exception) {
                Log::warning('Failed to broadcast reschedule chat message.', [
                    'message_id' => $message->id,
                    'booking_id' => $booking->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        });

        return $message;
    }

    /**
     * Total unread messages across every booking the user participates in.
     * Used for the sidebar and dashboard badge.
     */
    public function unreadCountForUser(User $user): int
    {
        return Message::query()
            ->whereNull('read_at')
            ->where('sender_id', '!=', $user->id)
            ->whereHas('conversation', function ($query) use ($user) {
                $query->where(function ($participant) use ($user) {
                    $participant->where('student_id', $user->id)
                        ->orWhere('instructor_id', $user->id);
                });
            })
            ->count();
    }

    public function markRead(Conversation $conversation, User $user): int
    {
        return $conversation->markReadFor($user);
    }

    public function touchLastMessage(Conversation $conversation, Message $message): void
    {
        $conversation->update(['last_message_at' => $message->created_at ?? now()]);
    }
}
