<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class StudentProfile extends Model
{
    protected $fillable = [
        'user_id',
        'skill_level',
        'is_first_time',
        'birthday',
        'country',
        'status',
    ];

    protected $casts = [
        'is_first_time' => 'boolean',
        'birthday' => 'date:Y-m-d',
    ];

    protected $appends = [
        'age',
    ];

    protected function age(): Attribute
    {
        return Attribute::get(fn (): ?int => $this->birthday?->age);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
