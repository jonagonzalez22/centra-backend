<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_stops', function (Blueprint $table) {
            $table->timestamp('estimated_arrival_at')->nullable()->after('logistics_notes');
            $table->unsignedInteger('travel_duration_seconds')->nullable()->after('estimated_arrival_at');
        });
    }

    public function down(): void
    {
        Schema::table('route_stops', function (Blueprint $table) {
            $table->dropColumn(['estimated_arrival_at', 'travel_duration_seconds']);
        });
    }
};
