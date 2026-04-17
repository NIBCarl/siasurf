<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentType;

class SafetyIncident extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'safety_incidents';

    protected $fillable = [
        'booking_id',
        'instructor_id',
        'reported_by',
        'type',
        'severity',
        'description',
        'location',
        'resolved_at',
    ];

    protected $casts = [
        'type' => IncidentType::class,
        'severity' => IncidentSeverity::class,
        'resolved_at' => 'datetime',
    ];

    // Relationships
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    // Scopes
    public function scopeByType($query, IncidentType $type)
    {
        return $query->where('type', $type);
    }

    public function scopeBySeverity($query, IncidentSeverity $severity)
    {
        return $query->where('severity', $severity);
    }

    public function scopeForInstructor($query, int $instructorId)
    {
        return $query->where('instructor_id', $instructorId);
    }

    public function scopeMinor($query)
    {
        return $query->where('severity', IncidentSeverity::Minor);
    }

    public function scopeMajor($query)
    {
        return $query->where('severity', IncidentSeverity::Major);
    }

    public function scopeCritical($query)
    {
        return $query->where('severity', IncidentSeverity::Critical);
    }

    public function scopeResolved($query)
    {
        return $query->whereNotNull('resolved_at');
    }

    public function scopeUnresolved($query)
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    // Helper methods
    public function isMinor(): bool
    {
        return $this->severity === IncidentSeverity::Minor;
    }

    public function isMajor(): bool
    {
        return $this->severity === IncidentSeverity::Major;
    }

    public function isCritical(): bool
    {
        return $this->severity === IncidentSeverity::Critical;
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    public function strikes(): int
    {
        return $this->severity->strikes();
    }

    public function resolve(): void
    {
        $this->update(['resolved_at' => now()]);
    }
}
