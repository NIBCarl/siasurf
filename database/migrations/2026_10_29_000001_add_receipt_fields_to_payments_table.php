<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GCash proof-of-payment workflow.
 *
 * Students upload a screenshot of their GCash receipt. The instructor (or SISA)
 * opens the full image and either approves it - which marks the payment
 * completed - or rejects it with a short note. The file itself lives on the
 * private `local` disk and is streamed through an authorised route, so it is
 * never exposed as a public URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('receipt_path')->nullable()->after('checkout_url');
            $table->string('receipt_reference')->nullable()->after('receipt_path');
            $table->timestamp('receipt_uploaded_at')->nullable()->after('receipt_reference');
            $table->timestamp('receipt_verified_at')->nullable()->after('receipt_uploaded_at');
            $table->foreignId('receipt_verified_by')->nullable()->after('receipt_verified_at')
                ->constrained('users')->nullOnDelete();
            $table->text('receipt_note')->nullable()->after('receipt_verified_by');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('receipt_verified_by');
            $table->dropColumn([
                'receipt_path',
                'receipt_reference',
                'receipt_uploaded_at',
                'receipt_verified_at',
                'receipt_note',
            ]);
        });
    }
};
