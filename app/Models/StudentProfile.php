<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentProfile extends Model
{
    protected $fillable = [
        'user_id',
        'skill_level',
        'is_first_time',
    ];

    protected $casts = [
        'is_first_time' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
