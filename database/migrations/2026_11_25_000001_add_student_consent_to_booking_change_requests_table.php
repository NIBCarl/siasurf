<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_change_requests', function (Blueprint $table) {
            $table->timestamp('student_notified_at')->nullable()->after('new_end_time');
            $table->timestamp('student_agreed_at')->nullable()->after('student_notified_at');
            $table->timestamp('student_declined_at')->nullable()->after('student_agreed_at');
            $table->text('student_response_note')->nullable()->after('student_declined_at');
        });
    }

    public function down(): void
    {
        Schema::table('booking_change_requests', function (Blueprint $table) {
            $table->dropColumn([
                'student_notified_at',
                'student_agreed_at',
                'student_declined_at',
                'student_response_note',
            ]);
        });
    }
};
