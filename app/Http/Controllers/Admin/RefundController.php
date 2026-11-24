<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RefundStatus;
use App\Exceptions\BookingChangeException;
use App\Http\Controllers\Controller;
use App\Models\Refund;
use App\Services\BookingCancellationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RefundController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Refund::class);

        $status = (string) $request->query('status', 'all');

        $refunds = Refund::with([
            'booking.student',
            'booking.instructor',
            'booking.surfSpot',
            'payment',
            'initiatedBy',
        ])
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->orderByRaw(
                'CASE WHEN status IN (?, ?, ?, ?) THEN 0 ELSE 1 END ASC',
                [
                    RefundStatus::ManualRequired->value,
                    RefundStatus::Failed->value,
                    RefundStatus::Requested->value,
                    RefundStatus::Processing->value,
                ]
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Refunds/Index', [
            'refunds' => $refunds,
            'filters' => ['status' => $status],
            'stats' => [
                'all' => Refund::count(),
                'requested' => Refund::where('status', RefundStatus::Requested->value)->count(),
                'processing' => Refund::where('status', RefundStatus::Processing->value)->count(),
                'manual_required' => Refund::where('status', RefundStatus::ManualRequired->value)->count(),
                'completed' => Refund::where('status', RefundStatus::Completed->value)->count(),
                'failed' => Refund::where('status', RefundStatus::Failed->value)->count(),
                'total_refunded_amount' => (float) Refund::completed()->sum('amount'),
                'total_service_fees_retained' => (float) Refund::sum('service_fee_retained'),
            ],
        ]);
    }

    public function retry(Refund $refund, BookingCancellationService $cancellationService): RedirectResponse
    {
        Gate::authorize('retry', $refund);

        try {
            $cancellationService->retryRefund($refund, auth()->user());
        } catch (BookingChangeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'PayMongo refund retry initiated.');
    }

    public function completeManual(
        Request $request,
        Refund $refund,
        BookingCancellationService $cancellationService
    ): RedirectResponse {
        Gate::authorize('completeManual', $refund);

        $validated = $request->validate([
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $cancellationService->completeManualRefund(
                $refund,
                auth()->user(),
                $validated['reference'] ?? null,
                $validated['note'] ?? null
            );
        } catch (BookingChangeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Refund marked as completed manually.');
    }
}
