<?php

namespace App\Models;

use App\Enums\FeedbackCategory;
use App\Enums\IncidentOutcome;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\IncidentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

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
        'report_type',
        'checked_items',
        'type',
        'severity',
        'suggested_severity',
        'suggested_outcome',
        'final_outcome',
        'strike_weight',
        'description',
        'evidence_path',
        'instructor_summary',
        'decision_reason',
        'admin_note',
        'handled_externally',
        'external_handled_at',
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
        'suggested_outcome' => IncidentOutcome::class,
        'final_outcome' => IncidentOutcome::class,
        'source' => IncidentSource::class,
        'status' => IncidentStatus::class,
        'checked_items' => 'array',
        'strike_weight' => 'integer',
        'handled_externally' => 'boolean',
        'external_handled_at' => 'datetime',
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

    public function fine(): HasOne
    {
        return $this->hasOne(InstructorFine::class);
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
        return $this->severity === IncidentSeverity::Critical
            || $this->final_outcome === IncidentOutcome::Critical;
    }

    public function isExternallyHandled(): bool
    {
        return (bool) $this->handled_externally
            || $this->final_outcome === IncidentOutcome::Critical;
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

    public function categoryEnum(): ?FeedbackCategory
    {
        return $this->category ? FeedbackCategory::tryFrom($this->category) : null;
    }

    /**
     * The strike weight that was actually applied.
     * Critical and dismissed incidents never carry in-system strikes.
     */
    public function strikes(): int
    {
        if (
            $this->isExternallyHandled()
            || $this->final_outcome === IncidentOutcome::NoStrike
        ) {
            return 0;
        }

        if ($this->final_outcome !== null) {
            return $this->final_outcome->strikes();
        }

        return max(0, (int) $this->strike_weight);
    }

    /**
     * Student-safe representation: never leaks suggested severity/outcome,
     * admin notes, or internal strike weights.
     *
     * @return array<string, mixed>
     */
    public function toStudentPayload(): array
    {
        $finalOutcomeLabel = null;
        if (! $this->isPending()) {
            $finalOutcomeLabel = $this->final_outcome?->label()
                ?? match ($this->status) {
                    IncidentStatus::Rejected => IncidentOutcome::NoStrike->label(),
                    IncidentStatus::Confirmed => $this->isExternallyHandled()
                        ? IncidentOutcome::Critical->label()
                        : 'Confirmed by SISA',
                    default => $this->status?->label(),
                };
        }

        return [
            'id' => $this->id,
            'status' => $this->status?->value,
            'report_type' => $this->report_type,
            'checked_items' => $this->checked_items ?? ($this->category ? [$this->category] : []),
            'description' => $this->description,
            'decision_reason' => ! $this->isPending() ? ($this->decision_reason ?? $this->rejection_reason) : null,
            'final_outcome_label' => $finalOutcomeLabel,
            'created_at' => $this->created_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
        ];
    }

    public function resolve(): void
    {
        $this->update(['resolved_at' => now()]);
    }
}
