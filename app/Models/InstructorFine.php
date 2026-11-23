<?php

namespace App\Models;

use App\Enums\FineStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstructorFine extends Model
{
    protected $fillable = [
        'instructor_id',
        'safety_incident_id',
        'instructor_strike_id',
        'amount',
        'penalty_type',
        'status',
        'reason',
        'issued_by',
        'issued_at',
        'paid_at',
        'settled_by',
        'settlement_note',
        'voided_at',
        'void_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'status' => FineStatus::class,
        'issued_at' => 'datetime',
        'paid_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(SafetyIncident::class, 'safety_incident_id');
    }

    public function strike(): BelongsTo
    {
        return $this->belongsTo(InstructorStrike::class, 'instructor_strike_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    public function scopeDue($query)
    {
        return $query->where('status', FineStatus::Due->value);
    }

    public function scopeForInstructor($query, int $instructorId)
    {
        return $query->where('instructor_id', $instructorId);
    }
}
