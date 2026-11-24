<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Models\Waiver;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Sent immediately to the parent/guardian email recorded during minor
 * parental consent so the guardian receives an auditable signed PDF copy and
 * can alert SISA/SiaSurf if the consent was not authorized.
 */
class ParentalConsentRecordedNotification extends Notification
{
    public function __construct(
        protected Booking $booking,
        protected Waiver $waiver
    ) {
        $this->booking = $booking->loadMissing(['student', 'instructor', 'surfSpot']);
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $reference = '#SURF-'.str_pad((string) $this->booking->id, 5, '0', STR_PAD_LEFT);
        $minorName = $this->waiver->participant_name ?: ($this->booking->student?->name ?? 'Minor Student');
        $minorAge = $this->waiver->age ?? $this->booking->student_age;
        $guardianName = $this->waiver->signed_by ?: 'Parent / Legal Guardian';
        $relationship = $this->waiver->guardian_relationship ?: 'Parent / Legal Guardian';
        $dateLabel = $this->booking->date?->format('F d, Y') ?? 'TBD';

        $timeRange = ($this->booking->start_time && $this->booking->end_time)
            ? $this->booking->start_time->format('g:i A').' – '.$this->booking->end_time->format('g:i A')
            : ($this->booking->time_period?->label() ?? 'Scheduled session');

        $signedAtLabel = $this->waiver->signed_at
            ? $this->waiver->signed_at->copy()->timezone(config('app.timezone', 'Asia/Manila'))->format('F d, Y h:i A')
            : now(config('app.timezone', 'Asia/Manila'))->format('F d, Y h:i A');

        $mail = (new MailMessage)
            ->subject("Parental Consent Recorded for {$minorName} ({$reference}) — SiaSurf")
            ->greeting("Hello {$guardianName},")
            ->line("A digital Parental Consent & Release form has been signed on SiaSurf authorizing {$minorName}"
                .($minorAge !== null ? " (age {$minorAge})" : '')
                .' to participate in a surf lesson.')
            ->line("- Booking Reference: {$reference}")
            ->line("- Parent / Guardian: {$guardianName} ({$relationship})")
            ->line('- SISA-Verified Instructor: '.($this->booking->instructor?->name ?? 'Assigned Instructor'))
            ->line('- Surf Spot: '.($this->booking->surfSpot?->name ?? 'To be confirmed'))
            ->line("- Lesson Date & Time: {$dateLabel} ({$timeRange})")
            ->line('- Emergency Contact: '.($this->waiver->emergency_contact ?: 'Provided on form'));

        if (! empty($this->waiver->medical_info)) {
            $mail->line('- Declared Medical Info / Allergies: '.$this->waiver->medical_info);
        }

        $mail->line("- Signed At: {$signedAtLabel}")
            ->line('A signed PDF copy of the Parental Consent Form is attached to this email for your records.')
            ->line('If you did not authorize or sign this parental consent form, please contact SISA/SiaSurf immediately so this booking can be cancelled.');

        if (! empty($this->waiver->pdf_path) && Storage::disk('local')->exists($this->waiver->pdf_path)) {
            $pdfName = 'SiaSurf_Parental_Consent_SURF-'.str_pad((string) $this->booking->id, 5, '0', STR_PAD_LEFT).'.pdf';
            $mail->attachData(Storage::disk('local')->get($this->waiver->pdf_path), $pdfName, [
                'mime' => 'application/pdf',
            ]);
        }

        return $mail;
    }
}
