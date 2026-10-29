<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

class ConversationService
{
    /**
     * Return the booking's thread, creating it the first time the booking is
     * confirmed. Existing threads are returned even after cancellation so the
     * participants keep their history.
     */
    public function ensureForBooking(Booking $booking): ?Conversation
    {
        $existing = $booking->conversation()->first();

        if ($existing) {
            return $existing;
        }

        if (!in_array($booking->status, [
            BookingStatus::Confirmed,
            BookingStatus::InProgress,
            BookingStatus::Completed,
        ], true)) {
            return null;
        }

        return Conversation::create([
            'booking_id' => $booking->id,
            'student_id' => $booking->student_id,
            'instructor_id' => $booking->instructor_id,
        ]);
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
