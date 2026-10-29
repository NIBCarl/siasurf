<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instructor_profiles', function (Blueprint $table) {
            $table->text('suspension_reason')->nullable()->after('suspended_until');
            $table->timestamp('suspended_at')->nullable()->after('suspension_reason');
        });

        // Backfill a suspension record for instructors suspended before this
        // workflow existed, so the suspended page and appeal flow have data.
        DB::table('instructor_profiles')
            ->where('status', 'suspended')
            ->orderBy('id')
            ->each(function ($profile) {
                $startsAt = $profile->updated_at ?? now();
                $endsAt = $profile->suspended_until;

                DB::table('instructor_suspensions')->insert([
                    'instructor_id' => $profile->user_id,
                    'type' => 'legacy',
                    'status' => 'active',
                    'reason' => 'Suspended by SISA before the appeal workflow was introduced.',
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'created_at' => $startsAt,
                    'updated_at' => $startsAt,
                ]);

                DB::table('instructor_profiles')->where('id', $profile->id)->update([
                    'suspension_reason' => 'Suspended by SISA before the appeal workflow was introduced.',
                    'suspended_at' => $startsAt,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('instructor_profiles', function (Blueprint $table) {
            $table->dropColumn(['suspension_reason', 'suspended_at']);
        });
    }
};
