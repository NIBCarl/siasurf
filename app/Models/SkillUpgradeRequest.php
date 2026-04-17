<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SkillUpgradeRequest extends Model
{
    protected $fillable = [
        'student_id',
        'instructor_id',
        'booking_id',
        'current_level',
        'requested_level',
        'status',
    ];

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function instructor()
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }
}
