<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The original create migration declares softDeletes(), but this database
        // predates that line, so SafetyIncident's global scope fails without it.
        if (! Schema::hasColumn('safety_incidents', 'deleted_at')) {
            Schema::table('safety_incidents', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('safety_incidents', 'deleted_at')) {
            Schema::table('safety_incidents', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
