<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('student_profiles', 'date_of_birth')) {
            return;
        }

        DB::table('student_profiles')
            ->whereNull('birthday')
            ->whereNotNull('date_of_birth')
            ->update(['birthday' => DB::raw('date_of_birth')]);

        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropColumn('date_of_birth');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('student_profiles', 'date_of_birth')) {
            return;
        }

        Schema::table('student_profiles', function (Blueprint $table) {
            $table->date('date_of_birth')->nullable()->after('skill_level');
        });
    }
};
