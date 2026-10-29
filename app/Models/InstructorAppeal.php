<?php

namespace App\Models;

use App\Enums\AppealGround;
use App\Enums\AppealStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstructorAppeal extends Model
{
    protected $fillable = [
        'instructor_id',
        'instructor_suspension_id',
        'message',
        'ground',
        'evidence_path',
        'status',
        'admin_response',
        'reviewed_by',
        'reviewed_at',
        'revoked_incident_ids',
    ];

    protected $casts = [
        'ground' => AppealGround::class,
        'status' => AppealStatus::class,
        'reviewed_at' => 'datetime',
        'revoked_incident_ids' => 'array',
    ];

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function suspension(): BelongsTo
    {
        return $this->belongsTo(InstructorSuspension::class, 'instructor_suspension_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', AppealStatus::Pending->value);
    }

    public function isPending(): bool
    {
        return $this->status === AppealStatus::Pending;
    }
}
