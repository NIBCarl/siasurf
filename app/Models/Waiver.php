<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Enums\WaiverType;

class Waiver extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'type',
        'signed_by',
        'signature_data',
        'pdf_path',
        'signed_at',
        'retention_until',
    ];

    protected $casts = [
        'type' => WaiverType::class,
        'signed_at' => 'datetime',
        'retention_until' => 'datetime',
    ];

    // Relationships
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    // Scopes
    public function scopeLiability($query)
    {
        return $query->where('type', WaiverType::Liability);
    }

    public function scopeParentalConsent($query)
    {
        return $query->where('type', WaiverType::ParentalConsent);
    }

    public function scopeForBooking($query, int $bookingId)
    {
        return $query->where('booking_id', $bookingId);
    }

    // Helper methods
    public function isLiability(): bool
    {
        return $this->type === WaiverType::Liability;
    }

    public function isParentalConsent(): bool
    {
        return $this->type === WaiverType::ParentalConsent;
    }

    public function isExpired(): bool
    {
        return $this->retention_until !== null && $this->retention_until < now();
    }

    public function daysUntilExpiry(): int
    {
        if ($this->retention_until === null) {
            return 0;
        }
        return now()->diffInDays($this->retention_until, false);
    }
}
