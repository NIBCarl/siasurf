<?php

use App\Enums\IncidentOutcome;
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
            $table->string('report_type')->nullable()->after('category');
            $table->json('checked_items')->nullable()->after('report_type');
            $table->string('suggested_outcome')->nullable()->after('suggested_severity');
            $table->string('final_outcome')->nullable()->after('suggested_outcome');
            $table->text('decision_reason')->nullable()->after('instructor_summary');
            $table->text('admin_note')->nullable()->after('decision_reason');
            $table->boolean('handled_externally')->default(false)->after('admin_note');
            $table->timestamp('external_handled_at')->nullable()->after('handled_externally');

            $table->index('final_outcome');
            $table->index('handled_externally');
        });

        DB::table('safety_incidents')->orderBy('id')->each(function ($incident) {
            $suggestedSeverity = $incident->suggested_severity
                ? IncidentSeverity::tryFrom((string) $incident->suggested_severity)
                : null;
            $suggestedOutcome = IncidentOutcome::fromLegacySeverity($suggestedSeverity);

            $finalOutcome = null;
            $handledExternally = false;
            $externalHandledAt = null;

            if ($incident->status === 'rejected') {
                $finalOutcome = IncidentOutcome::NoStrike->value;
            } elseif ($incident->status === 'confirmed') {
                if ($incident->severity === IncidentSeverity::Critical->value) {
                    $finalOutcome = IncidentOutcome::Critical->value;
                    $handledExternally = true;
                    $externalHandledAt = $incident->confirmed_at ?? $incident->created_at;
                } else {
                    $finalOutcome = match ((int) ($incident->strike_weight ?? 0)) {
                        1 => IncidentOutcome::OneStrike->value,
                        2 => IncidentOutcome::TwoStrikes->value,
                        3 => IncidentOutcome::ThreeStrikes->value,
                        default => IncidentOutcome::fromLegacySeverity(
                            $incident->severity ? IncidentSeverity::tryFrom((string) $incident->severity) : null
                        )?->value,
                    };
                }
            }

            DB::table('safety_incidents')->where('id', $incident->id)->update([
                'suggested_outcome' => $suggestedOutcome?->value,
                'final_outcome' => $finalOutcome,
                'handled_externally' => $handledExternally,
                'external_handled_at' => $externalHandledAt,
                'decision_reason' => $incident->rejection_reason ?? $incident->instructor_summary,
            ]);
        });

        Schema::table('instructor_suspensions', function (Blueprint $table) {
            $table->unsignedTinyInteger('strike_tier')->nullable()->after('type');
            $table->timestamp('reinstatement_eligible_at')->nullable()->after('ends_at');
            $table->timestamp('reinstated_at')->nullable()->after('reinstatement_eligible_at');
            $table->foreignId('reinstated_by')->nullable()->after('reinstated_at')
                ->constrained('users')->nullOnDelete();
            $table->text('reinstatement_note')->nullable()->after('reinstated_by');
            $table->boolean('requires_sisa_id_confiscation')->default(false)->after('reinstatement_note');
        });

        DB::table('instructor_suspensions')
            ->where('type', 'automatic')
            ->update([
                'strike_tier' => 3,
                'requires_sisa_id_confiscation' => true,
            ]);
    }

    public function down(): void
    {
        Schema::table('instructor_suspensions', function (Blueprint $table) {
            $table->dropForeign(['reinstated_by']);
            $table->dropColumn([
                'strike_tier',
                'reinstatement_eligible_at',
                'reinstated_at',
                'reinstated_by',
                'reinstatement_note',
                'requires_sisa_id_confiscation',
            ]);
        });

        Schema::table('safety_incidents', function (Blueprint $table) {
            $table->dropIndex(['final_outcome']);
            $table->dropIndex(['handled_externally']);
            $table->dropColumn([
                'report_type',
                'checked_items',
                'suggested_outcome',
                'final_outcome',
                'decision_reason',
                'admin_note',
                'handled_externally',
                'external_handled_at',
            ]);
        });
    }
};
