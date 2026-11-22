<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->boolean('needs_hotel_pickup')->default(false)->after('has_board');
            $table->string('hotel_name', 255)->nullable()->after('needs_hotel_pickup');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['needs_hotel_pickup', 'hotel_name']);
        });
    }
};
