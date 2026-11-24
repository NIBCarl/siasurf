<?php

namespace App\Http\Controllers;

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
                ->map(fn (DatabaseNotification $notification) => [
                    'id' => $notification->id,
                    'type' => $notification->data['type'] ?? '',
                    'message' => $notification->data['message'] ?? 'You have a new update.',
                    'data' => $notification->data,
                    'read' => $notification->read_at !== null,
                    'created_at' => $notification->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'unread' => $user->unreadNotifications()->count(),
        ];
    }
}
