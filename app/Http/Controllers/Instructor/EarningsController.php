<?php

namespace App\Http\Controllers\Instructor;

use App\Enums\LedgerSettlementStatus;
use App\Http\Controllers\Controller;
use App\Models\InstructorDailyLedger;
use App\Models\InstructorPayout;
use App\Services\EarningsLedgerService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EarningsController extends Controller
{
    public function index(Request $request, EarningsLedgerService $ledgerService): Response
    {
        $user = $request->user();
        $status = (string) $request->query('status', 'all');
        $from = $request->query('from');
        $to = $request->query('to');
        $today = now(config('app.timezone', 'Asia/Manila'))->toDateString();

        $ledgerQuery = InstructorDailyLedger::query()
            ->forInstructor($user->id)
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
            ->when(! empty($from), fn ($q) => $q->whereDate('ledger_date', '>=', (string) $from))
            ->when(! empty($to), fn ($q) => $q->whereDate('ledger_date', '<=', (string) $to))
            ->orderByDesc('ledger_date')
            ->orderByDesc('id');

        $ledgers = $ledgerQuery
            ->paginate(15, ['*'], 'ledgers_page')
            ->withQueryString();

        $payouts = InstructorPayout::query()
            ->where('instructor_id', $user->id)
            ->with('recordedBy:id,name')
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'payouts_page')
            ->withQueryString();

        return Inertia::render('Instructor/Earnings/Index', [
            'summary' => $ledgerService->getInstructorSummary($user),
            'ledgers' => $ledgers,
            'payouts' => $payouts,
            'filters' => [
                'status' => in_array($status, ['all', 'unsettled', 'overdue', 'settled'], true) ? $status : 'all',
                'from' => $from,
                'to' => $to,
            ],
            'remittanceDays' => EarningsLedgerService::remittanceDays(),
        ]);
    }
}
