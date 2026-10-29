<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Sends a real email through the configured mailer.
 *
 * Gmail SMTP setup is easy to get subtly wrong (spaces in the App Password,
 * using the account password instead of an App Password, port 465 vs 587).
 * This command isolates that from the registration flow.
 *
 *   php artisan mail:test you@gmail.com
 */
class TestMailConfiguration extends Command
{
    protected $signature = 'mail:test {email : Recipient address}';

    protected $description = 'Send a test email to verify the MAIL_* configuration (e.g. Gmail SMTP)';

    public function handle(): int
    {
        $recipient = (string) $this->argument('email');
        $mailer = (string) config('mail.default');

        $this->line('Mailer      : '.$mailer);

        if ($mailer === 'log') {
            $this->line('Host        : (none - log driver)');
            $this->warn('MAIL_MAILER=log writes the message to storage/logs/laravel.log instead of sending it.');
        } else {
            $this->line('Host        : '.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port'));
            $this->line('Username    : '.config('mail.mailers.smtp.username'));
            $this->line('From        : '.config('mail.from.address'));
        }

        try {
            Mail::raw(
                "This is a test email from SiaSurf.\n\n"
                ."If you can read this, email verification will reach the Gmail address used at registration.",
                function ($message) use ($recipient) {
                    $message->to($recipient)->subject('SiaSurf mail configuration test');
                }
            );
        } catch (\Throwable $exception) {
            $this->newLine();
            $this->error('Sending failed: '.$exception->getMessage());
            $this->newLine();
            $this->line('Checklist:');
            $this->line('  1. MAIL_MAILER=smtp');
            $this->line('  2. MAIL_HOST=smtp.gmail.com, MAIL_PORT=587, MAIL_SCHEME=null');
            $this->line('  3. MAIL_USERNAME is the full Gmail address');
            $this->line('  4. MAIL_PASSWORD is a 16-character Google App Password (no spaces)');
            $this->line('  5. MAIL_FROM_ADDRESS matches MAIL_USERNAME');
            $this->line('  6. Outbound port 587 is not blocked by a firewall/antivirus');
            $this->line('  7. Run `php artisan config:clear` after editing .env');

            return self::FAILURE;
        }

        $this->info("Test email sent to {$recipient}.");

        return self::SUCCESS;
    }
}
