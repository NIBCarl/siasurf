<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waivers', function (Blueprint $table) {
            $table->string('guardian_relationship', 50)->nullable()->after('signed_by_guardian');
            $table->string('guardian_email', 255)->nullable()->after('guardian_relationship');
            $table->timestamp('guardian_notified_at')->nullable()->after('guardian_email');
        });

        Schema::table('booking_participants', function (Blueprint $table) {
            $table->string('guardian_relationship', 50)->nullable()->after('guardian_name');
            $table->string('guardian_email', 255)->nullable()->after('guardian_phone');
        });
    }

    public function down(): void
    {
        Schema::table('waivers', function (Blueprint $table) {
            $table->dropColumn([
                'guardian_relationship',
                'guardian_email',
                'guardian_notified_at',
            ]);
        });

        Schema::table('booking_participants', function (Blueprint $table) {
            $table->dropColumn([
                'guardian_relationship',
                'guardian_email',
            ]);
        });
    }
};
