<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waivers', function (Blueprint $table) {
            $table->string('participant_name')->nullable()->after('type');
            $table->date('birthday')->nullable()->after('participant_name');
            $table->unsignedTinyInteger('age')->nullable()->after('birthday');
            $table->string('email')->nullable()->after('age');
            $table->string('country', 100)->nullable()->after('email');
            $table->string('hotel_name', 255)->nullable()->after('country');
            $table->string('emergency_contact', 255)->nullable()->after('hotel_name');
            $table->string('surf_shop', 255)->nullable()->after('emergency_contact');
            $table->string('instructor_name', 255)->nullable()->after('surf_shop');
            $table->string('witness_name', 255)->nullable()->after('instructor_name');
            $table->boolean('signed_by_guardian')->default(false)->after('witness_name');
        });

        Schema::table('booking_participants', function (Blueprint $table) {
            $table->date('birthday')->nullable()->after('full_name');
            $table->string('email')->nullable()->after('age');
            $table->string('country', 100)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('waivers', function (Blueprint $table) {
            $table->dropColumn([
                'participant_name',
                'birthday',
                'age',
                'email',
                'country',
                'hotel_name',
                'emergency_contact',
                'surf_shop',
                'instructor_name',
                'witness_name',
                'signed_by_guardian',
            ]);
        });

        Schema::table('booking_participants', function (Blueprint $table) {
            $table->dropColumn(['birthday', 'email', 'country']);
        });
    }
};
