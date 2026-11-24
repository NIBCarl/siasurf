<?php

namespace App\Services;

use App\Enums\CertificateStatus;
use App\Enums\CertificateType;
use App\Enums\InstructorStatus;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

class SisaIdCardService
{
    /**
     * Build the dynamic SISA Instructor Portrait ID card payload for an instructor.
     *
     * @return array<string, mixed>
     */
    public function build(User $instructor): array
    {
        $profile = $instructor->instructorProfile;

        $verifiedCerts = $instructor->certificates()
            ->where('status', CertificateStatus::Verified)
            ->orderBy('verified_at')
            ->get();

        $sisaCerts = $verifiedCerts->where('type', CertificateType::SISA);

        $issuedAt = $sisaCerts->whereNotNull('verified_at')->first()?->verified_at
            ?? $verifiedCerts->whereNotNull('verified_at')->first()?->verified_at
            ?? $profile?->created_at
            ?? $instructor->created_at
            ?? now();

        $expiresAt = $sisaCerts->whereNotNull('expires_at')->sortByDesc('expires_at')->first()?->expires_at
            ?? $verifiedCerts->whereNotNull('expires_at')->sortByDesc('expires_at')->first()?->expires_at
            ?? $issuedAt->copy()->addYears(2);

        $sisaIdCode = sprintf('SISA %d-%04d', $issuedAt->year, $instructor->id);

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
