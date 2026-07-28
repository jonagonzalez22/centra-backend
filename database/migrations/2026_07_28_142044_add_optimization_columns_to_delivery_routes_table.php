<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_routes', function (Blueprint $table) {
            $table->timestamp('planned_at')->nullable()->after('status');
            $table->time('departure_time')->nullable()->after('planned_at');
            $table->text('encoded_polyline')->nullable()->after('departure_time');
            $table->unsignedSmallInteger('unload_time_minutes_snapshot')->nullable()->after('encoded_polyline');
            $table->boolean('requires_recalculation')->default(false)->after('unload_time_minutes_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_routes', function (Blueprint $table) {
            $table->dropColumn([
                'planned_at',
                'departure_time',
                'encoded_polyline',
                'unload_time_minutes_snapshot',
                'requires_recalculation',
            ]);
        });
    }
};
