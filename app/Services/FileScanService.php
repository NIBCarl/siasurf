<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Uploaded-file gate for instructor certificates (Phase 13, item 6).
 *
 * Two layers:
 *  1. Always verify the real MIME type and size server-side. Laravel's `mimes`
 *     rule trusts the client-supplied extension, so a .php renamed to .pdf
 *     would otherwise pass.
 *  2. When VIRUS_SCAN_ENABLED=true, run the file through ClamAV's clamdscan.
 *
 * Scanning is off by default because the binary is not present on typical
 * XAMPP installs. When it *is* switched on but the scanner is missing, uploads
 * are rejected rather than silently accepted unscanned — a security control must
 * fail closed.
 */
class FileScanService
{
    /** MIME types a certificate is allowed to be, keyed by extension. */
    private const ALLOWED_MIMES = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
    ];

    public function isEnabled(): bool
    {
        return (bool) config('services.virus_scan.enabled', false);
    }

    /**
     * @throws RuntimeException when the file is unacceptable.
     */
    public function assertSafe(UploadedFile $file): void
    {
        $this->assertRealMimeType($file);

        if ($this->isEnabled()) {
            $this->scanWithClamAv($file);
        }
    }

    /**
     * Reject a file whose contents do not match its extension.
     *
     * @throws RuntimeException
     */
    public function assertRealMimeType(UploadedFile $file): void
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $allowed = self::ALLOWED_MIMES[$extension] ?? null;

        if ($allowed === null) {
            throw new RuntimeException('Only PDF, JPG and PNG certificates are accepted.');
        }

        $detected = $file->getMimeType();

        if (! in_array($detected, $allowed, true)) {
            throw new RuntimeException(
                "The file \"{$file->getClientOriginalName()}\" does not look like a real {$extension} file."
            );
        }
    }

    /**
     * @throws RuntimeException
     */
    private function scanWithClamAv(UploadedFile $file): void
    {
        $binary = config('services.virus_scan.binary', 'clamdscan');
        $path = $file->getRealPath();

        if ($path === false || ! is_readable($path)) {
            throw new RuntimeException('The uploaded file could not be read for scanning.');
        }

        $command = sprintf('%s --no-summary --stdout %s 2>&1', $binary, escapeshellarg($path));
        $output = [];
        $exitCode = 0;

        @exec($command, $output, $exitCode);

        // 127 = command not found. Fail closed: an enabled control that cannot
        // run is a misconfiguration, not a reason to skip the check.
        if ($exitCode === 127) {
            Log::error('Virus scanning is enabled but clamdscan is not available.');

            throw new RuntimeException(
                'Virus scanning is enabled but is not available on this server. Contact the administrator.'
            );
        }

        // clamdscan: 0 = clean, 1 = infected, 2 = error.
        if ($exitCode === 1) {
            Log::warning('Rejected an infected upload.', [
                'file' => $file->getClientOriginalName(),
                'output' => implode(' ', $output),
            ]);

            throw new RuntimeException(
                "The file \"{$file->getClientOriginalName()}\" failed the malware scan."
            );
        }

        if ($exitCode > 1) {
            Log::error('Virus scan failed to run.', ['exit_code' => $exitCode, 'output' => $output]);

            throw new RuntimeException('The uploaded file could not be scanned. Please try again.');
        }
    }
}
