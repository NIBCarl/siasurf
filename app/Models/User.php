<?php

namespace App\Models;

use App\Enums\CertificateStatus;
use App\Enums\InstructorStatus;
use App\Enums\SuspensionStatus;
use App\Services\AvatarService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Implementing the MustVerifyEmail contract is what actually turns the
 * `verified` route middleware on. The base Authenticatable class ships the
 * trait, but EnsureEmailIsVerified only enforces verification when the model
 * is an instance of the contract - without this line every `verified` route
 * silently behaved like `auth` only.
 */
class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, HasRoles, Notifiable;

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

    /**
     * Every serialized user carries a ready-to-use avatar URL so the front end
     * never has to guess between a stored path and a full URL.
     *
     * @var list<string>
     */
    protected $appends = [
        'avatar_url',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Resolve the stored avatar path to a browser-usable URL.
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => AvatarService::url($this->avatar_path));
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

    public function instructorSuspensions(): HasMany
    {
        return $this->hasMany(InstructorSuspension::class, 'instructor_id');
    }

    public function instructorStrikes(): HasMany
    {
        return $this->hasMany(InstructorStrike::class, 'instructor_id');
    }

    public function instructorAppeals(): HasMany
    {
        return $this->hasMany(InstructorAppeal::class, 'instructor_id');
    }

    public function activeInstructorSuspension(): ?InstructorSuspension
    {
        return $this->instructorSuspensions()
            ->where('status', SuspensionStatus::Active->value)
            ->latest()
            ->first();
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
               $this->instructorProfile->status === InstructorStatus::Active;
    }

    public function hasVerifiedCertificate(): bool
    {
        return $this->certificates()->where('status', CertificateStatus::Verified)->exists();
    }

    public function isBookable(): bool
    {
        return $this->isVerifiedInstructor() &&
               ($this->instructorProfile->suspended_until === null || $this->instructorProfile->suspended_until <= now());
    }
}
