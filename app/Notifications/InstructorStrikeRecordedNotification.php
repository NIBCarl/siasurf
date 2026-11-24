<?php

namespace App\Notifications;

use App\Models\InstructorProfile;
use App\Models\InstructorStrike;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InstructorStrikeRecordedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected int $activeTotal;

    public function __construct(protected InstructorStrike $strike, ?int $activeTotal = null)
    {
        $this->activeTotal = $activeTotal ?? (int) (
            InstructorProfile::where('user_id', $strike->instructor_id)->value('strike_count') ?? $strike->weight
        );
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $fineAmount = number_format((float) config('siasurf.strikes.fine_amount', 500), 0);
        $tier2Days = (int) config('siasurf.strikes.tier2_suspension_days', 7);
        $tier3Days = (int) config('siasurf.strikes.tier3_suspension_days', 30);

        $penaltyLine = match (true) {
            $this->activeTotal >= 3 => "Penalty applied: {$tier3Days}-day (1-month) suspension. Coordinate with SISA regarding your SISA ID.",
            $this->activeTotal === 2 => "Penalty applied: {$tier2Days}-day (1-week) suspension. Reinstatement requires SISA administrator approval.",
            default => "Penalty applied: Official warning and a ₱{$fineAmount} fine recorded as due (to be settled with SISA outside the system).",
        };

        $reason = $this->strike->incident?->instructor_summary
            ?: ($this->strike->reason_text ?? $this->strike->reason_code ?? 'Confirmed safety concern');

        return (new MailMessage)
            ->subject('SISA Strike Decision Recorded on Your Account')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('SISA reviewed and confirmed a report and recorded '.$this->strike->weight.' strike(s) on your instructor record.')
            ->line('Reason: '.$reason)
            ->line('Current active strikes: '.$this->activeTotal.' / 3')
            ->line($penaltyLine)
            ->action('View Dashboard', route('instructor.dashboard'))
            ->line('SiaSurf Safety Monitoring');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'strike_recorded',
            'strike_id' => $this->strike->id,
            'weight' => $this->strike->weight,
            'active_total' => $this->activeTotal,
            'message' => 'SISA recorded '.$this->strike->weight.' strike(s) on your instructor record (Active total: '.$this->activeTotal.').',
            'url' => route('instructor.dashboard'),
        ];
    }
}
