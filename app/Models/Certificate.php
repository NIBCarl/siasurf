<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Enums\CertificateStatus;
use App\Enums\CertificateType;

class Certificate extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'file_path',
        'status',
        'verified_at',
        'expires_at',
        'admin_notes',
    ];

    protected $casts = [
        'type' => CertificateType::class,
        'status' => CertificateStatus::class,
        'verified_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Scopes
    public function scopeVerified($query)
    {
        return $query->where('status', CertificateStatus::Verified);
    }

    public function scopePending($query)
    {
        return $query->where('status', CertificateStatus::PendingVerification);
    }

    public function scopeExpiringSoon($query, int $days = 30)
    {
        return $query->whereNotNull('expires_at')
                     ->where('expires_at', '<=', now()->addDays($days))
                     ->where('expires_at', '>=', now());
    }

    public function scopeExpired($query)
    {
        return $query->whereNotNull('expires_at')
                     ->where('expires_at', '<', now());
    }

    public function scopeByType($query, CertificateType $type)
    {
        return $query->where('type', $type);
    }

    // Helper methods
    public function isVerified(): bool
    {
        return $this->status === CertificateStatus::Verified;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at < now();
    }

    public function isExpiringSoon(int $days = 30): bool
    {
        return $this->expires_at !== null && 
               $this->expires_at <= now()->addDays($days) && 
               $this->expires_at >= now();
    }

    public function markAsVerified(): void
    {
        $this->update([
            'status' => CertificateStatus::Verified,
            'verified_at' => now(),
        ]);
    }

    public function markAsRejected(string $notes = null): void
    {
        $this->update([
            'status' => CertificateStatus::Rejected,
            'admin_notes' => $notes,
        ]);
    }
}
