<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * InstructorSearchController uses whereFullText() on instructor_profiles.bio.
     * Without this index MySQL falls back to a LIKE '%term%' full scan (and some
     * strict configurations error outright), so the search stayed slow.
     *
     * FULLTEXT is MySQL/MariaDB-only; SQLite/PostgreSQL installs silently skip it
     * and keep using the LIKE fallback.
     */
    public function up(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        if (! $this->hasFullTextIndex()) {
            Schema::table('instructor_profiles', function (Blueprint $table) {
                $table->fullText('bio', 'instructor_profiles_bio_fulltext');
            });
        }
    }

    public function down(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        if ($this->hasFullTextIndex()) {
            Schema::table('instructor_profiles', function (Blueprint $table) {
                $table->dropFullText('instructor_profiles_bio_fulltext');
            });
        }
    }

    private function hasFullTextIndex(): bool
    {
        $database = DB::connection()->getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', 'instructor_profiles')
            ->where('index_name', 'instructor_profiles_bio_fulltext')
            ->exists();
    }
};
