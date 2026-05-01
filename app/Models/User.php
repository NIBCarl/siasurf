<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'phone',
        'avatar_path',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // Relationships
    public function instructorProfile(): HasOne
    {
        return $this->hasOne(InstructorProfile::class);
    }

    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(Availability::class);
    }

    public function bookingsAsStudent(): HasMany
    {
        return $this->hasMany(Booking::class, 'student_id');
    }

    public function bookingsAsInstructor(): HasMany
    {
        return $this->hasMany(Booking::class, 'instructor_id');
    }

    public function reviewsAsStudent(): HasMany
    {
        return $this->hasMany(Review::class, 'student_id');
    }

    public function reviewsAsInstructor(): HasMany
    {
        return $this->hasMany(Review::class, 'instructor_id');
    }

    public function safetyIncidents(): HasMany
    {
        return $this->hasMany(SafetyIncident::class, 'instructor_id');
    }

    public function reportedIncidents(): HasMany
    {
        return $this->hasMany(SafetyIncident::class, 'reported_by');
    }

    public function skillUpgradeRequests(): HasMany
    {
        return $this->hasMany(SkillUpgradeRequest::class, 'student_id');
    }

    public function submittedUpgradeRequests(): HasMany
    {
        return $this->hasMany(SkillUpgradeRequest::class, 'instructor_id');
    }

    // Scopes
    public function scopeStudents($query)
    {
        return $query->where('role', 'student');
    }

    public function scopeInstructors($query)
    {
        return $query->where('role', 'instructor');
    }

    public function scopeAdmins($query)
    {
        return $query->where('role', 'admin');
    }

    // Helper methods
    public function isStudent(): bool
    {
        return $this->role === 'student';
    }

    public function isInstructor(): bool
    {
        return $this->role === 'instructor';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function hasInstructorProfile(): bool
    {
        return $this->instructorProfile !== null;
    }

    public function isVerifiedInstructor(): bool
    {
        return $this->isInstructor() && 
               $this->instructorProfile !== null && 
               $this->instructorProfile->status === \App\Enums\InstructorStatus::Active;
    }

    public function hasVerifiedCertificate(): bool
    {
        return $this->certificates()->where('status', \App\Enums\CertificateStatus::Verified)->exists();
    }

    public function isBookable(): bool
    {
        return $this->isVerifiedInstructor() && 
               ($this->instructorProfile->suspended_until === null || $this->instructorProfile->suspended_until <= now());
    }
}
