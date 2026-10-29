<?php

namespace App\Http\Controllers;

use App\Enums\BookingStatus;
use App\Events\MessageSent;
use App\Http\Requests\StoreMessageRequest;
use App\Models\Booking;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    public function __construct(private ConversationService $conversations)
    {
    }

    /**
     * Return the booking's message history and chat availability.
     */
    public function index(Request $request, Booking $booking): JsonResponse
    {
        Gate::authorize('view', $booking);

        $user = $request->user();
        $conversation = $this->conversations->ensureForBooking($booking);

        if (!$conversation) {
            return response()->json([
                'conversation' => null,
                'other_party' => $this->otherPartyPayload($booking, $user->id),
                'messages' => [],
                'can_send' => false,
                'read_only_reason' => 'Chat becomes available once the instructor confirms this booking.',
            ]);
        }

        $this->authorize('view', $conversation);

        $conversation->markReadFor($user);

        $messages = $conversation->messages()
            ->with('sender:id,name')
            ->get()
            ->map(fn (Message $message) => $this->messagePayload($message, $user->id))
            ->values();

        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'booking_id' => $conversation->booking_id,
                'can_send' => $conversation->canSendFor($user),
                'read_only_reason' => $this->readOnlyReason($conversation),
            ],
            'other_party' => $this->otherPartyPayload($booking, $user->id),
            'messages' => $messages,
            'unread_count' => 0,
        ]);
    }

    /**
     * Send a message inside the booking thread.
     */
    public function store(StoreMessageRequest $request, Booking $booking): JsonResponse
    {
        Gate::authorize('view', $booking);

        $user = $request->user();
        $conversation = $this->conversations->ensureForBooking($booking);

        abort_unless($conversation instanceof Conversation, 403, 'Chat is not available for this booking yet.');

        $this->authorize('view', $conversation);
        $this->authorize('sendMessage', $conversation);

        $message = $conversation->messages()->create([
            'sender_id' => $user->id,
            'body' => $request->validated('body'),
        ]);

        $message->load('sender:id,name');
        $this->conversations->touchLastMessage($conversation, $message);

        try {
            broadcast(new MessageSent($message))->toOthers();
        } catch (\Throwable $exception) {
            // The message is already stored. A missing Reverb process must not
            // turn a successful send into a 500; the recipient will still see it
            // the next time the panel loads.
            Log::warning('Chat broadcast failed.', [
                'message_id' => $message->id,
                'booking_id' => $booking->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'message' => $this->messagePayload($message, $user->id),
            'can_send' => true,
            'read_only_reason' => null,
        ], 201);
    }

    /**
     * Mark every message addressed to the current user as read.
     */
    public function read(Request $request, Booking $booking): JsonResponse
    {
        Gate::authorize('view', $booking);

        $conversation = $this->conversations->ensureForBooking($booking);

        abort_unless($conversation instanceof Conversation, 404);

        $this->authorize('view', $conversation);

        $marked = $this->conversations->markRead($conversation, $request->user());

        return response()->json([
            'unread_count' => 0,
            'marked' => $marked,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function messagePayload(Message $message, int $viewerId): array
    {
        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'sender_id' => $message->sender_id,
            'sender_name' => $message->sender?->name ?? 'SiaSurf',
            'body' => $message->body,
            'mine' => $message->sender_id === $viewerId,
            'read_at' => $message->read_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
            'created_at_label' => $message->created_at?->format('M j, g:i A'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function otherPartyPayload(Booking $booking, int $viewerId): array
    {
        $other = $booking->student_id === $viewerId
            ? $booking->instructor
            : $booking->student;

        return [
            'id' => $other?->id,
            'name' => $other?->name ?? 'SiaSurf participant',
            'avatar_path' => $other?->avatar_path,
        ];
    }

    /**
     * Explain why the composer is disabled without exposing internal notes.
     */
    private function readOnlyReason(Conversation $conversation): ?string
    {
        $status = $conversation->booking?->status;

        if ($status === BookingStatus::Completed) {
            return 'This lesson is complete. The conversation is read-only.';
        }

        if (in_array($status, [
            BookingStatus::Cancelled,
            BookingStatus::Declined,
            BookingStatus::PaymentTimeout,
            BookingStatus::Expired,
            BookingStatus::NoShow,
        ], true)) {
            return 'This booking is closed. The conversation is read-only.';
        }

        if (!$conversation->canSendFor(auth()->user())) {
            return 'Chat becomes available once the booking is confirmed.';
        }

        return null;
    }
}
