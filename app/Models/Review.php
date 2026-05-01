<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'student_id',
        'instructor_id',
        'rating',
        'comment',
        'instructor_response',
        'photo_path',
        'edited_at',
        'is_hidden',
        'moderation_reason',
        'moderated_at',
        'moderated_by',
    ];

    protected $casts = [
        'rating' => 'integer',
        'edited_at' => 'datetime',
        'is_hidden' => 'boolean',
        'moderated_at' => 'datetime',
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

    // Scopes
    public function scopeForInstructor($query, int $instructorId)
    {
        return $query->where('instructor_id', $instructorId);
    }

    public function scopeForStudent($query, int $studentId)
    {
        return $query->where('student_id', $studentId);
    }

    public function scopeWithRating($query, int $rating)
    {
        return $query->where('rating', $rating);
    }

    public function scopeWithPhoto($query)
    {
        return $query->whereNotNull('photo_path');
    }

    public function scopeWithResponse($query)
    {
        return $query->whereNotNull('instructor_response');
    }

    public function scopeEdited($query)
    {
        return $query->whereNotNull('edited_at');
    }

    public function scopeVisible($query)
    {
        return $query->where('is_hidden', false)->orWhereNull('is_hidden');
    }

    // Helper methods
    public function hasPhoto(): bool
    {
        return $this->photo_path !== null;
    }

    public function hasResponse(): bool
    {
        return $this->instructor_response !== null;
    }

    public function isEdited(): bool
    {
        return $this->edited_at !== null;
    }

    public function isPositive(): bool
    {
        return $this->rating >= 4;
    }

    public function isNegative(): bool
    {
        return $this->rating <= 2;
    }

    public function respond(string $response): void
    {
        $this->update(['instructor_response' => $response]);
    }

    public function edit(string $comment, ?int $rating = null): void
    {
        $this->update([
            'comment' => $comment,
            'rating' => $rating,
            'edited_at' => now(),
        ]);
    }
}
