<?php

namespace App\Models;

use App\Enums\SuspensionStatus;
use App\Enums\SuspensionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstructorSuspension extends Model
{
    protected $fillable = [
        'instructor_id',
        'type',
        'strike_tier',
        'status',
        'reason',
        'starts_at',
        'ends_at',
        'reinstatement_eligible_at',
        'reinstated_at',
        'reinstated_by',
        'reinstatement_note',
        'requires_sisa_id_confiscation',
        'suspended_by',
        'triggering_incident_id',
    ];

    protected $casts = [
        'type' => SuspensionType::class,
        'strike_tier' => 'integer',
        'status' => SuspensionStatus::class,
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'reinstatement_eligible_at' => 'datetime',
        'reinstated_at' => 'datetime',
        'requires_sisa_id_confiscation' => 'boolean',
    ];

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function suspendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suspended_by');
    }

    public function reinstatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reinstated_by');
    }

    public function triggeringIncident(): BelongsTo
    {
        return $this->belongsTo(SafetyIncident::class, 'triggering_incident_id');
    }

    public function appeals(): HasMany
    {
        return $this->hasMany(InstructorAppeal::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', SuspensionStatus::Active->value);
    }

    public function scopeEnforced($query)
    {
        return $query->whereIn('status', [
            SuspensionStatus::Active->value,
            SuspensionStatus::AwaitingReinstatement->value,
        ]);
    }

    public function scopeForInstructor($query, int $instructorId)
    {
        return $query->where('instructor_id', $instructorId);
    }

    public function isActive(): bool
    {
        return $this->status === SuspensionStatus::Active;
    }

    public function isEnforced(): bool
    {
        return $this->status?->isEnforced() ?? false;
    }

    public function isAwaitingReinstatement(): bool
    {
        return $this->status === SuspensionStatus::AwaitingReinstatement;
    }

    public function isTier3(): bool
    {
        return $this->type === SuspensionType::Automatic && ($this->strike_tier ?? 3) >= 3;
    }

    public function shouldResetStrikesOnLift(): bool
    {
        return $this->isTier3() || $this->type === SuspensionType::Legacy;
    }

    public function tierLabel(): string
    {
        if ($this->type === SuspensionType::Manual) {
            return 'Administrative Hold (Manual)';
        }

        if ($this->type === SuspensionType::Legacy) {
            return 'Legacy Suspension';
        }

        return ($this->strike_tier ?? 3) >= 3
            ? '1-Month Suspension (3 Strikes)'
            : '1-Week Suspension (2 Strikes)';
    }

    public function latestAppeal(): ?InstructorAppeal
    {
        return $this->appeals()->latest()->first();
    }
}
