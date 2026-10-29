<?php

use App\Enums\IncidentSeverity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('safety_incidents', function (Blueprint $table) {
            $table->string('severity')->nullable()->change();

            $table->string('source')->default('admin')->after('reported_by');
            $table->string('status')->default('confirmed')->after('source');
            $table->string('category')->nullable()->after('status');
            $table->string('suggested_severity')->nullable()->after('severity');
            $table->unsignedTinyInteger('strike_weight')->default(0)->after('suggested_severity');
            $table->foreignId('review_id')->nullable()->after('booking_id')
                ->constrained('reviews')->nullOnDelete();
            $table->string('evidence_path')->nullable()->after('description');
            $table->text('instructor_summary')->nullable()->after('evidence_path');
            $table->timestamp('confirmed_at')->nullable()->after('resolved_at');
            $table->foreignId('confirmed_by')->nullable()->after('confirmed_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('confirmed_by');
            $table->foreignId('rejected_by')->nullable()->after('rejected_at')
                ->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable()->after('rejected_by');
            $table->timestamp('withdrawn_at')->nullable()->after('rejection_reason');
            $table->timestamp('strike_applied_at')->nullable()->after('withdrawn_at');

            $table->index('source');
            $table->index('status');
            $table->index(['status', 'created_at']);
            $table->index(['instructor_id', 'status']);
            $table->unique('review_id');
        });

        // Existing rows were created by admins and already counted toward the
        // old strike_count. Mark them as confirmed and already applied so the
        // new StrikeService cannot double-count them.
        DB::table('safety_incidents')->orderBy('id')->each(function ($incident) {
            $severity = IncidentSeverity::tryFrom((string) $incident->severity);

            DB::table('safety_incidents')->where('id', $incident->id)->update([
                'source' => 'admin',
                'status' => 'confirmed',
                'strike_weight' => $severity?->strikes() ?? 0,
                'confirmed_at' => $incident->created_at,
                'strike_applied_at' => $incident->created_at,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('safety_incidents', function (Blueprint $table) {
            $table->dropForeign(['review_id']);
            $table->dropForeign(['confirmed_by']);
            $table->dropForeign(['rejected_by']);
            $table->dropUnique(['review_id']);
            $table->dropIndex(['source']);
            $table->dropIndex(['status']);
            $table->dropIndex(['status', 'created_at']);
            $table->dropIndex(['instructor_id', 'status']);
            $table->dropColumn([
                'source',
                'status',
                'category',
                'suggested_severity',
                'strike_weight',
                'review_id',
                'evidence_path',
                'instructor_summary',
                'confirmed_at',
                'confirmed_by',
                'rejected_at',
                'rejected_by',
                'rejection_reason',
                'withdrawn_at',
                'strike_applied_at',
            ]);
        });

        Schema::table('safety_incidents', function (Blueprint $table) {
            $table->string('severity')->nullable(false)->change();
        });
    }
};
