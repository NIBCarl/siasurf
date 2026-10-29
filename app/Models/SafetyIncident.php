<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\IncidentType;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SafetyIncident extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'safety_incidents';

    protected $fillable = [
        'booking_id',
        'review_id',
        'instructor_id',
        'reported_by',
        'source',
        'status',
        'category',
        'type',
        'severity',
        'suggested_severity',
        'strike_weight',
        'description',
        'evidence_path',
        'instructor_summary',
        'location',
        'resolved_at',
        'confirmed_at',
        'confirmed_by',
        'rejected_at',
        'rejected_by',
        'rejection_reason',
        'withdrawn_at',
        'strike_applied_at',
    ];

    protected $casts = [
        'type' => IncidentType::class,
        'severity' => IncidentSeverity::class,
        'suggested_severity' => IncidentSeverity::class,
        'source' => IncidentSource::class,
        'status' => IncidentStatus::class,
        'strike_weight' => 'integer',
        'resolved_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'rejected_at' => 'datetime',
        'withdrawn_at' => 'datetime',
        'strike_applied_at' => 'datetime',
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

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function strike(): HasOne
    {
        return $this->hasOne(InstructorStrike::class);
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

    public function scopePending($query)
    {
        return $query->where('status', IncidentStatus::Pending->value);
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', IncidentStatus::Confirmed->value);
    }

    public function scopeStudentReports($query)
    {
        return $query->where('source', IncidentSource::StudentFeedback->value);
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

    public function isPending(): bool
    {
        return $this->status === IncidentStatus::Pending;
    }

    public function isConfirmed(): bool
    {
        return $this->status === IncidentStatus::Confirmed;
    }

    public function isStudentReport(): bool
    {
        return $this->source === IncidentSource::StudentFeedback;
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    public function categoryEnum(): ?\App\Enums\FeedbackCategory
    {
        return $this->category ? \App\Enums\FeedbackCategory::tryFrom($this->category) : null;
    }

    /**
     * The strike weight that was actually applied. Falls back to the severity
     * for legacy rows that predate the strike_weight column.
     */
    public function strikes(): int
    {
        if ($this->strike_weight > 0) {
            return $this->strike_weight;
        }

        return $this->severity?->strikes() ?? 0;
    }

    public function resolve(): void
    {
        $this->update(['resolved_at' => now()]);
    }
}
