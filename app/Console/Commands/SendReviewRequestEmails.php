<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Notifications\SendReviewRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendReviewRequestEmails extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reviews:send-requests 
                            {--hours=2 : Hours after completion to send request}
                            {--dry-run : Show what would be sent without actually sending}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send review request emails to students for completed bookings';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hours = $this->option('hours');
        $dryRun = $this->option('dry-run');
        $targetTime = now()->subHours($hours);

        $this->info("Sending review requests for bookings completed {$hours} hours ago...");

        // Find bookings that were completed around the target time and don't have reviews
        $bookings = Booking::where('status', BookingStatus::Completed->value)
            ->where('updated_at', '<=', $targetTime)
            ->where('updated_at', '>=', $targetTime->copy()->subHour())
            ->whereDoesntHave('review')
            ->whereDoesntHave('notifications', function ($query) {
                $query->where('type', 'App\\Notifications\\SendReviewRequest');
            })
            ->with(['student', 'instructor'])
            ->get();

        if ($bookings->isEmpty()) {
            $this->info('No bookings found that need review requests.');
            return self::SUCCESS;
        }

        $this->info("Found {$bookings->count()} booking(s) to send review requests.");

        foreach ($bookings as $booking) {
            $studentName = $booking->student->name;
            $instructorName = $booking->instructor->name;

            if ($dryRun) {
                $this->line("[DRY RUN] Would send review request to {$studentName} for lesson with {$instructorName}");
                continue;
            }

            try {
                $booking->student->notify(new SendReviewRequest($booking));
                $this->line("✓ Sent review request to {$studentName} for lesson with {$instructorName}");
            } catch (\Exception $e) {
                $this->error("✗ Failed to send review request to {$studentName}: {$e->getMessage()}");
            }
        }

        $this->info('Done!');

        return self::SUCCESS;
    }
}
