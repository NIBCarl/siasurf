<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instructor_payouts', function (Blueprint $table) {
            $table->id();
            $table->string('reference_code', 40)->unique();
            $table->foreignId('instructor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('settlement_type', 30);
            $table->string('payment_channel', 30)->default('in_person_cash');
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('ledger_days_count')->default(0);
            $table->decimal('gcash_earnings_settled', 10, 2)->default(0.00);
            $table->decimal('cash_fees_settled', 10, 2)->default(0.00);
            $table->decimal('net_payout_amount', 10, 2)->default(0.00);
            $table->string('receipt_reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->index(['instructor_id', 'paid_at']);
            $table->index('settlement_type');
            $table->index('paid_at');
        });

        Schema::create('instructor_daily_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('instructor_id')->constrained('users')->restrictOnDelete();
            $table->date('ledger_date');
            $table->unsignedInteger('completed_bookings_count')->default(0);
            $table->unsignedInteger('gcash_bookings_count')->default(0);
            $table->unsignedInteger('cash_bookings_count')->default(0);
            $table->decimal('gcash_earnings', 10, 2)->default(0.00);
            $table->decimal('cash_earnings', 10, 2)->default(0.00);
            $table->decimal('total_earnings', 10, 2)->default(0.00);
            $table->decimal('platform_gcash_fees', 10, 2)->default(0.00);
            $table->decimal('cash_service_fees_owed', 10, 2)->default(0.00);
            $table->decimal('net_payable', 10, 2)->default(0.00);
            $table->date('remittance_due_date')->nullable();
            $table->string('settlement_status', 30)->default('unsettled');
            $table->foreignId('payout_id')->nullable()->constrained('instructor_payouts')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
            $table->timestamps();

            $table->index(['instructor_id', 'ledger_date']);
            $table->index(['instructor_id', 'settlement_status']);
            $table->index(['settlement_status', 'remittance_due_date'], 'idl_status_due_date_idx');
            $table->index('payout_id');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('daily_ledger_id')
                ->nullable()
                ->after('instructor_retained_amount')
                ->constrained('instructor_daily_ledgers')
                ->nullOnDelete();
            $table->string('ledger_entry_type', 30)->nullable()->after('daily_ledger_id');
            $table->index('daily_ledger_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['daily_ledger_id']);
            $table->dropIndex(['daily_ledger_id']);
            $table->dropColumn(['daily_ledger_id', 'ledger_entry_type']);
        });

        Schema::dropIfExists('instructor_daily_ledgers');
        Schema::dropIfExists('instructor_payouts');
    }
};
