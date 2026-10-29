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
        'status',
        'reason',
        'starts_at',
        'ends_at',
        'suspended_by',
        'triggering_incident_id',
    ];

    protected $casts = [
        'type' => SuspensionType::class,
        'status' => SuspensionStatus::class,
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function suspendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suspended_by');
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

    public function scopeForInstructor($query, int $instructorId)
    {
        return $query->where('instructor_id', $instructorId);
    }

    public function isActive(): bool
    {
        return $this->status === SuspensionStatus::Active;
    }

    public function latestAppeal(): ?InstructorAppeal
    {
        return $this->appeals()->latest()->first();
    }
}
