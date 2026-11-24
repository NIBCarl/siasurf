<?php

namespace App\Services;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\InstructorStatus;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;

class SisaIdCardService
{
    /**
     * Resolve the single-source-of-truth SISA ID code and validity dates for an instructor.
     *
     * @return array{sisa_id_code: string, issued_at: CarbonInterface, expires_at: CarbonInterface}
     */
    public function resolveValidity(User $instructor): array
    {
        $profile = $instructor->instructorProfile;

        $allCerts = $instructor->relationLoaded('certificates')
            ? $instructor->certificates
            : $instructor->certificates()->get();

        $verifiedCerts = $allCerts
            ->where('status', CertificateStatus::Verified)
            ->sortBy('verified_at')
            ->values();
        $sisaCerts = $verifiedCerts->where('type', CertificateType::SISA);

        $expiredCerts = $allCerts
            ->where('status', CertificateStatus::Expired)
            ->sortByDesc('expires_at')
            ->values();
        $expiredSisaCerts = $expiredCerts->where('type', CertificateType::SISA);

        $issuedAt = $sisaCerts->whereNotNull('verified_at')->first()?->verified_at
            ?? $verifiedCerts->whereNotNull('verified_at')->first()?->verified_at
            ?? $expiredSisaCerts->whereNotNull('verified_at')->sortBy('verified_at')->first()?->verified_at
            ?? $expiredCerts->whereNotNull('verified_at')->sortBy('verified_at')->first()?->verified_at
            ?? $profile?->created_at
            ?? $instructor->created_at
            ?? now();

        $expiresAt = $sisaCerts->whereNotNull('expires_at')->sortByDesc('expires_at')->first()?->expires_at
            ?? $verifiedCerts->whereNotNull('expires_at')->sortByDesc('expires_at')->first()?->expires_at
            ?? ($verifiedCerts->isEmpty()
                ? ($expiredSisaCerts->whereNotNull('expires_at')->first()?->expires_at
                    ?? $expiredCerts->whereNotNull('expires_at')->first()?->expires_at)
                : null)
            ?? $issuedAt->copy()->addYears(2);

        return [
            'sisa_id_code' => sprintf('SISA %d-%04d', $issuedAt->year, $instructor->id),
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Build the dynamic SISA Instructor Portrait ID card payload for an instructor.
     *
     * @return array<string, mixed>
     */
    public function build(User $instructor): array
    {
        $profile = $instructor->instructorProfile;
        $validity = $this->resolveValidity($instructor);
        $issuedAt = $validity['issued_at'];
        $expiresAt = $validity['expires_at'];
        $sisaIdCode = $validity['sisa_id_code'];

        $hasQr = $profile !== null && (
            $profile->status === InstructorStatus::Active
            || ($profile->qr_code_path !== null && Storage::disk('local')->exists($profile->qr_code_path))
        );

        return [
            'instructor_id' => $instructor->id,
            'name' => $instructor->name,
            'avatar_url' => $instructor->avatar_url,
            'avatar_path' => $instructor->avatar_path,
            'level' => $profile?->level?->value ?? 1,
            'level_label' => $profile?->level?->shortLabel() ?? 'Level 1',
            'sisa_id_code' => $sisaIdCode,
            'birthdate' => $profile?->birthday ? strtoupper($profile->birthday->format('d M Y')) : null,
            'date_issued' => strtoupper($issuedAt->format('d F Y')),
            'exp_date' => strtoupper($expiresAt->format('d F Y')),
            'qr_image_url' => $hasQr ? route('qr.image', $instructor->id) : null,
            'president_name' => 'NILDIE E. BLANCADA',
            'president_title' => 'SISA President',
        ];
    }
}
