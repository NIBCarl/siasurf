<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentSkillAssessment extends Model
{
    protected $fillable = [
        'user_id',
        'can_swim_deep_water',
        'is_first_time',
        'selected_skills',
        'answers',
        'checklist_level',
        'knowledge_level',
        'knowledge_score',
        'knowledge_total',
        'water_safety_passed',
        'assessed_level',
        'safety_flags',
        'confirmed_at',
    ];

    protected $casts = [
        'can_swim_deep_water' => 'boolean',
        'is_first_time' => 'boolean',
        'selected_skills' => 'array',
        'answers' => 'array',
        'knowledge_score' => 'integer',
        'knowledge_total' => 'integer',
        'water_safety_passed' => 'boolean',
        'safety_flags' => 'array',
        'confirmed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasSafetyWarnings(): bool
    {
        foreach ($this->safety_flags ?? [] as $flag) {
            if (($flag['severity'] ?? null) === 'warning') {
                return true;
            }
        }

        return false;
    }
}
