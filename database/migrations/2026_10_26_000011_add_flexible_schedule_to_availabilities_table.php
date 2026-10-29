<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Flexible weekly schedule:
     *  - start_time/end_time already exist; they now carry the instructor's
     *    custom working range for that day and period.
     *  - open_slots stores the individual hourly slots the instructor has
     *    chosen to keep open. Null means "all slots in the range are open".
     */
    public function up(): void
    {
        Schema::table('availabilities', function (Blueprint $table) {
            $table->json('open_slots')->nullable()->after('end_time');
            $table->unsignedTinyInteger('slot_duration_minutes')->default(60)->after('open_slots');
        });
    }

    public function down(): void
    {
        Schema::table('availabilities', function (Blueprint $table) {
            $table->dropColumn(['open_slots', 'slot_duration_minutes']);
        });
    }
};
