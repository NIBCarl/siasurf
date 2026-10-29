<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Storage for GCash proof-of-payment screenshots.
 *
 * Files live on the private `local` disk. They are never exposed as a public
 * URL: the only way to read one is PaymentController@receiptImage, which runs
 * the booking policy first. That matters because a receipt shows the student's
 * full name, GCash number and reference id.
 */
class PaymentReceiptService
{
    /** Disk that holds the receipt images (storage/app/private). */
    public const DISK = 'local';

    /** Folder inside the disk. Every delete is scoped to this folder. */
    public const DIRECTORY = 'payment-receipts';

    /** Largest accepted receipt, in kilobytes (5 MB). */
    public const MAX_KILOBYTES = 5120;

    /** Image types a GCash screenshot can reasonably use. */
    public const MIMES = 'jpg,jpeg,png,webp';

    /**
     * Store (or replace) the receipt for a payment and move it into the
     * "awaiting verification" state.
     */
    public function store(Payment $payment, UploadedFile $file, ?string $reference = null): string
    {
        $extension = strtolower($file->extension() ?: 'jpg');

        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        $path = $file->storeAs(
            self::DIRECTORY,
            sprintf('payment-%d-%s.%s', $payment->id, Str::uuid()->toString(), $extension),
            self::DISK,
        );

        if ($path === false) {
            throw new \RuntimeException('The GCash receipt could not be written to the private disk.');
        }

        $this->delete($payment->receipt_path);

        $payment->update([
            'receipt_path' => $path,
            'receipt_reference' => $reference,
            'receipt_uploaded_at' => now(),
            'receipt_verified_at' => null,
            'receipt_verified_by' => null,
            'receipt_note' => null,
            'status' => PaymentStatus::PendingVerification,
        ]);

        return $path;
    }

    /**
     * Absolute path of the stored receipt, or null when there is none.
     */
    public function absolutePath(Payment $payment): ?string
    {
        if (! $payment->hasReceipt()) {
            return null;
        }

        $disk = Storage::disk(self::DISK);

        return $disk->exists($payment->receipt_path)
            ? $disk->path($payment->receipt_path)
            : null;
    }

    /**
     * Inline the receipt so the browser can render it (no forced download).
     */
    public function response(Payment $payment): BinaryFileResponse
    {
        $path = $this->absolutePath($payment);

        abort_if($path === null, 404, 'This payment has no receipt on file.');

        return response()->file($path, [
            'Cache-Control' => 'private, max-age=0, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Delete a stored receipt. The path is constrained to the receipts folder
     * so a tampered column can never delete unrelated files.
     */
    public function delete(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        $path = ltrim($path, '/');

        if (! str_starts_with($path, self::DIRECTORY.'/')) {
            return;
        }

        $disk = Storage::disk(self::DISK);

        if ($disk->exists($path)) {
            $disk->delete($path);
        }
    }
}
