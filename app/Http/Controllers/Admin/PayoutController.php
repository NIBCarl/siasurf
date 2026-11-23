<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InstructorDailyLedger;
use App\Models\InstructorPayout;
use App\Models\User;
use App\Services\EarningsLedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PayoutController extends Controller
{
    public function index(Request $request, EarningsLedgerService $ledgerService): Response
    {
        Gate::authorize('viewAny', InstructorPayout::class);

        $filter = (string) $request->query('filter', 'all');
        $search = trim((string) $request->query('search', ''));

        $instructorsQuery = User::query()
            ->instructors()
            ->whereHas('instructorProfile')
            ->with('instructorProfile')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderBy('name');

        $instructorSummaries = $instructorsQuery->get()
            ->map(function (User $instructor) use ($ledgerService) {
                $summary = $ledgerService->getInstructorSummary($instructor);

                return [
                    'id' => $instructor->id,
                    'name' => $instructor->name,
                    'email' => $instructor->email,
                    'level' => $instructor->instructorProfile?->level?->value,
                    'profile_status' => $instructor->instructorProfile?->status?->value,
                    'summary' => $summary,
                ];
            })
            ->filter(function (array $row) use ($filter) {
                $summary = $row['summary'];

                return match ($filter) {
                    'payout_due' => $summary['net_payable'] > 0,
                    'remittance_due' => $summary['net_payable'] < 0 || $summary['unsettled_cash_fees_owed'] > 0,
                    'overdue' => $summary['has_overdue_remittance'] === true,
                    'settled' => $summary['unsettled_days_count'] === 0 && $summary['payout_count'] > 0,
                    default => $summary['unsettled_days_count'] > 0 || $summary['payout_count'] > 0,
                };
            })
            ->sort(function (array $a, array $b) {
                $aOverdue = $a['summary']['has_overdue_remittance'] ? 1 : 0;
                $bOverdue = $b['summary']['has_overdue_remittance'] ? 1 : 0;
                if ($aOverdue !== $bOverdue) {
                    return $bOverdue <=> $aOverdue;
                }

                $aUnsettled = $a['summary']['unsettled_days_count'] > 0 ? 1 : 0;
                $bUnsettled = $b['summary']['unsettled_days_count'] > 0 ? 1 : 0;
                if ($aUnsettled !== $bUnsettled) {
                    return $bUnsettled <=> $aUnsettled;
                }

                return abs($b['summary']['net_payable']) <=> abs($a['summary']['net_payable']);
            })
            ->values();

        $payouts = InstructorPayout::query()
            ->with(['instructor:id,name,email', 'recordedBy:id,name'])
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate(15, ['*'], 'payouts_page')
            ->withQueryString();

        return Inertia::render('Admin/Payouts/Index', [
            'platformStats' => $ledgerService->getPlatformSummary(),
            'instructors' => $instructorSummaries,
            'payouts' => $payouts,
            'filters' => [
                'filter' => in_array($filter, ['all', 'payout_due', 'remittance_due', 'overdue', 'settled'], true) ? $filter : 'all',
                'search' => $search,
            ],
            'remittanceDays' => EarningsLedgerService::remittanceDays(),
        ]);
    }

    public function show(Request $request, User $instructor, EarningsLedgerService $ledgerService): Response
    {
        Gate::authorize('viewAny', InstructorPayout::class);
        abort_unless($instructor->isInstructor(), 404);

        $instructor->load('instructorProfile');
        $status = (string) $request->query('status', 'all');
        $today = now(config('app.timezone', 'Asia/Manila'))->toDateString();

        $unsettledLedgers = InstructorDailyLedger::query()
            ->forInstructor($instructor->id)
            ->unsettled()
            ->with([
                'bookings' => fn ($query) => $query
                    ->with(['student:id,name', 'surfSpot:id,name', 'payment:id,booking_id,payment_method,status,amount,lesson_price,service_fee_amount,paid_at'])
                    ->orderBy('id'),
            ])
            ->orderBy('ledger_date')
            ->orderBy('id')
            ->get();

        $ledgers = InstructorDailyLedger::query()
            ->forInstructor($instructor->id)
            ->with([
                'payout',
                'bookings' => fn ($query) => $query
                    ->with(['student:id,name', 'surfSpot:id,name', 'payment:id,booking_id,payment_method,status,amount,lesson_price,service_fee_amount,paid_at'])
                    ->orderBy('id'),
            ])
            ->when($status === 'settled', fn ($q) => $q->whereNotNull('payout_id'))
            ->when($status === 'unsettled', fn ($q) => $q->whereNull('payout_id'))
            ->when($status === 'overdue', fn ($q) => $q
                ->whereNull('payout_id')
                ->where('cash_service_fees_owed', '>', 0)
                ->whereNotNull('remittance_due_date')
                ->whereDate('remittance_due_date', '<', $today))
            ->orderByDesc('ledger_date')
            ->orderByDesc('id')
            ->paginate(15, ['*'], 'ledgers_page')
            ->withQueryString();

        $payouts = InstructorPayout::query()
            ->where('instructor_id', $instructor->id)
            ->with('recordedBy:id,name')
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'payouts_page')
            ->withQueryString();

        return Inertia::render('Admin/Payouts/Show', [
            'instructor' => $instructor,
            'summary' => $ledgerService->getInstructorSummary($instructor),
            'unsettledLedgers' => $unsettledLedgers,
            'ledgers' => $ledgers,
            'payouts' => $payouts,
            'filters' => [
                'status' => in_array($status, ['all', 'unsettled', 'overdue', 'settled'], true) ? $status : 'all',
            ],
            'remittanceDays' => EarningsLedgerService::remittanceDays(),
        ]);
    }

    public function store(Request $request, User $instructor, EarningsLedgerService $ledgerService): RedirectResponse
    {
        Gate::authorize('create', InstructorPayout::class);
        abort_unless($instructor->isInstructor(), 404);

        $validated = $request->validate([
            'payment_channel' => ['nullable', 'string', 'in:in_person_cash,in_person_gcash,offset'],
            'period_end' => ['nullable', 'date'],
            'ledger_ids' => ['nullable', 'array'],
            'ledger_ids.*' => ['integer'],
            'receipt_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $payout = $ledgerService->recordInPersonSettlement($instructor, $request->user(), $validated);
        } catch (\DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $net = (float) $payout->net_payout_amount;
        $formatted = '₱' . number_format(abs($net), 2);

        $message = match ($payout->settlement_type?->value) {
            'payout' => "In-person payout of {$formatted} recorded ({$payout->reference_code}).",
            'remittance' => "In-person cash fee remittance of {$formatted} recorded ({$payout->reference_code}).",
            default => "Zero-cash offset settlement recorded ({$payout->reference_code}).",
        };

        return back()->with('success', $message);
    }
}
