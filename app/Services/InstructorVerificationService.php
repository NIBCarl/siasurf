<?php

namespace App\Services;

use App\Enums\CertificateStatus;
use App\Enums\InstructorStatus;
use App\Models\User;

class InstructorVerificationService
{
    /**
     * Resolve the live public verification state for an instructor.
     *
     * The QR code itself never changes; this method is the single place that
     * decides what a scan should show today.
     *
     * @return array<string, mixed>
     */
    public function summarize(User $instructor): array
    {
        $profile = $instructor->instructorProfile;

        $certificates = $instructor->certificates()
            ->where('status', CertificateStatus::Verified)
            ->orderBy('expires_at')
            ->get();

        $hasValidCertificate = $certificates->contains(
            fn ($certificate) => !$certificate->isExpired()
        );

        $suspended = $profile?->status === InstructorStatus::Suspended
            || ($profile?->suspended_until !== null && $profile->suspended_until->isFuture());

        $status = match (true) {
            $profile === null => 'unverified',
            $suspended => 'suspended',
            $profile->status === InstructorStatus::PendingVerification => 'pending',
            $profile->status === InstructorStatus::Inactive => 'inactive',
            !$hasValidCertificate => 'unverified',
            default => 'verified',
        };

        return [
            'status' => $status,
            'label' => $this->label($status),
            'message' => $this->message($status),
            'tone' => $this->tone($status),
            'is_verified' => $status === 'verified',
            'can_book' => $status === 'verified' && $instructor->isBookable(),
            'suspended_until' => $profile?->suspended_until?->toIso8601String(),
            'checked_at' => now()->toIso8601String(),
            'certifications' => $certificates->map(fn ($certificate) => [
                'id' => $certificate->id,
                'type' => $certificate->type->value,
                'label' => $certificate->type->label(),
                'expires_at' => $certificate->expires_at?->format('M j, Y'),
                'expired' => $certificate->isExpired(),
            ])->values(),
        ];
    }

    private function label(string $status): string
    {
        return match ($status) {
            'verified' => 'SISA Verified',
            'suspended' => 'Suspended',
            'pending' => 'Verification pending',
            'inactive' => 'Inactive',
            default => 'Not verified',
        };
    }

    private function message(string $status): string
    {
        return match ($status) {
            'verified' => 'This instructor is verified and authorised to teach surf lessons in Siargao.',
            'suspended' => 'This instructor is temporarily suspended and is not authorised to teach. Do not book.',
            'pending' => 'SISA has not approved this instructor yet. Do not book until verification is complete.',
            'inactive' => 'This instructor is not currently active and is not accepting lessons.',
            default => 'No valid certification is on file. This person is not authorised to teach through SiaSurf.',
        };
    }

    private function tone(string $status): string
    {
        return match ($status) {
            'verified' => 'verified',
            'suspended' => 'critical',
            'pending' => 'pending',
            default => 'neutral',
        };
    }
}
