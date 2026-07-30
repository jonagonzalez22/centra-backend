<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_stops', function (Blueprint $table) {
            $table->uuid('completed_by')->nullable()->after('travel_duration_seconds');
            $table->timestamp('completed_at')->nullable()->after('completed_by');
            $table->decimal('gps_lat', 10, 7)->nullable()->after('completed_at');
            $table->decimal('gps_lon', 10, 7)->nullable()->after('gps_lat');

            $table->foreign('completed_by')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::table('route_stops', function (Blueprint $table) {
            $table->dropForeign(['completed_by']);
            $table->dropColumn(['completed_by', 'completed_at', 'gps_lat', 'gps_lon']);
        });
    }
};
