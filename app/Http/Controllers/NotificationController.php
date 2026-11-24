<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    private const FEED_LIMIT = 50;

    /**
     * The persisted notification feed for the signed-in user.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->feed($request));
    }

    /**
     * Mark one notification as read.
     */
    public function read(Request $request, string $notification): JsonResponse
    {
        $this->find($request, $notification)->markAsRead();

        return response()->json($this->feed($request));
    }

    /**
     * Mark every notification as read.
     */
    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json($this->feed($request));
    }

    /**
     * Dismiss one notification.
     */
    public function destroy(Request $request, string $notification): JsonResponse
    {
        $this->find($request, $notification)->delete();

        return response()->json($this->feed($request));
    }

    /**
     * Dismiss every notification.
     */
    public function clear(Request $request): JsonResponse
    {
        $request->user()->notifications()->delete();

        return response()->json($this->feed($request));
    }

    /**
     * Scoped lookup so a user can only touch their own notifications.
     */
    private function find(Request $request, string $id): DatabaseNotification
    {
        return $request->user()->notifications()->whereKey($id)->firstOrFail();
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, unread: int}
     */
    private function feed(Request $request): array
    {
        $user = $request->user();

        return [
            'items' => $user->notifications()
                ->latest()
                ->limit(self::FEED_LIMIT)
                ->get()
                ->map(function (DatabaseNotification $notification) use ($user) {
                    $data = $notification->data;
                    $type = $data['type'] ?? '';
                    $message = $data['message'] ?? 'You have a new update.';

                    if (
                        $type === 'instructor_rejected'
                        && filled($data['reason'] ?? null)
                        && ! str_contains($message, (string) $data['reason'])
                    ) {
                        $message = rtrim($message, '. ').'. Reason: '.$data['reason'];
                    }

                    return [
                        'id' => $notification->id,
                        'type' => $type,
                        'message' => $message,
                        'data' => $data,
                        'read' => $notification->read_at !== null,
                        'created_at' => $notification->created_at?->toIso8601String(),
                        // Newer notifications carry their own role-aware url. Older
                        // rows predate that field, so fall back to a safe guess
                        // rather than leaving the item unclickable.
                        'url' => $data['url'] ?? $this->fallbackUrl($user, $data),
                    ];
                })
                ->values()
                ->all(),
            'unread' => $user->unreadNotifications()->count(),
        ];
    }

    /**
     * Best-effort destination for rows written before `url` was part of the
     * payload. The role decides which console can actually open the record.
     *
     * @param  array<string, mixed>  $data
     */
    private function fallbackUrl(User $user, array $data): ?string
    {
        $bookingId = $data['booking_id'] ?? null;

        // Only deep-link a booking the instructor actually owns; the policy
        // would 403 otherwise, so their dashboard is the honest destination.
        if (is_numeric($bookingId)) {
            $id = (int) $bookingId;

            return match ($user->role) {
                'student' => route('student.bookings.show', $id),
                'admin' => route('admin.bookings.show', $id),
                'instructor' => Booking::query()
                    ->whereKey($id)
                    ->where('instructor_id', $user->id)
                    ->exists()
                        ? route('instructor.bookings.show', $id)
                        : route('instructor.dashboard'),
                default => null,
            };
        }

        if (is_numeric($data['incident_id'] ?? null) && $user->isAdmin()) {
            return route('admin.incidents.show', (int) $data['incident_id']);
        }

        if (is_numeric($data['appeal_id'] ?? null) && $user->isAdmin()) {
            return route('admin.appeals.show', (int) $data['appeal_id']);
        }

        if (($data['type'] ?? '') === 'instructor_rejected' && $user->isInstructor()) {
            return route('instructor.onboarding');
        }

        return match ($user->role) {
            'admin' => route('admin.dashboard'),
            'instructor' => route('instructor.dashboard'),
            default => route('student.dashboard'),
        };
    }
}
