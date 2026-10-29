<?php

namespace App\Models;

use App\Enums\IncidentSource;
use App\Enums\StrikeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstructorStrike extends Model
{
    protected $fillable = [
        'instructor_id',
        'safety_incident_id',
        'weight',
        'reason_code',
        'reason_text',
        'source',
        'status',
        'applied_by',
        'applied_at',
        'revoked_by',
        'revoked_at',
        'revocation_reason',
    ];

    protected $casts = [
        'weight' => 'integer',
        'source' => IncidentSource::class,
        'status' => StrikeStatus::class,
        'applied_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(SafetyIncident::class, 'safety_incident_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', StrikeStatus::Active->value);
    }
}
