<?php

namespace App\Models;

use App\Enums\BookingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'student_id',
        'instructor_id',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    // Relationships
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }

    public function latestMessage()
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    /**
     * Messages the user has received and not yet opened.
     */
    public function unreadCountFor(User $user): int
    {
        return $this->messages()
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * Mark every message addressed to this user as read.
     */
    public function markReadFor(User $user): int
    {
        return $this->messages()
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function isParticipant(User $user): bool
    {
        return in_array($user->id, [$this->student_id, $this->instructor_id], true);
    }

    /**
     * Chat is writable while the lesson is live or still ahead. Completed and
     * cancelled bookings retain their history read-only.
     */
    public function canSendFor(User $user): bool
    {
        if (!$this->isParticipant($user)) {
            return false;
        }

        return in_array($this->booking?->status, [
            BookingStatus::Confirmed,
            BookingStatus::InProgress,
        ], true);
    }

    public function otherParticipantFor(User $user): ?User
    {
        if ($user->id === $this->student_id) {
            return $this->instructor;
        }

        if ($user->id === $this->instructor_id) {
            return $this->student;
        }

        return null;
    }
}
