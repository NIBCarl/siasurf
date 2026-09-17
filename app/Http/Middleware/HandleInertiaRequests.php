<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        if ($user && $user->role === 'student') {
            $user->load(['studentProfile', 'skillUpgradeRequests' => function ($query) {
                $query->where('status', 'pending');
            }]);
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'tideWarning' => fn () => $request->session()->get('tideWarning'),
            ],
            'uploads' => [
                'maxFileMb' => $this->maxUploadFileMb(),
                'maxRequestMb' => $this->maxPostSizeMb(),
                'accepted' => ['pdf', 'jpg', 'jpeg', 'png'],
            ],
        ];
    }

    /**
     * The effective per-file upload limit: the app's own 10MB rule, capped by
     * PHP's upload_max_filesize. Without this the UI promises more than the
     * server accepts and PHP silently discards oversized files.
     */
    private function maxUploadFileMb(): int
    {
        $phpLimitMb = (int) floor($this->iniToBytes('upload_max_filesize') / 1048576);

        return max(1, $phpLimitMb > 0 ? min(10, $phpLimitMb) : 10);
    }

    /**
     * The effective total request limit, capped by PHP's post_max_size.
     */
    private function maxPostSizeMb(): int
    {
        $phpLimitMb = (int) floor($this->iniToBytes('post_max_size') / 1048576);

        return max(1, $phpLimitMb > 0 ? min(20, $phpLimitMb) : 20);
    }

    /**
     * Convert a php.ini shorthand size (e.g. "12M", "1G") to bytes.
     */
    private function iniToBytes(string $key): int
    {
        $value = trim((string) ini_get($key));

        if ($value === '') {
            return 0;
        }

        $bytes = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $bytes * 1073741824,
            'm' => $bytes * 1048576,
            'k' => $bytes * 1024,
            default => $bytes,
        };
    }
}
