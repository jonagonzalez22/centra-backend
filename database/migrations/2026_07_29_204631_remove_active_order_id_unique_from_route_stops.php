<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove the unique constraint on active_order_id (generated column).
     *
     * This constraint enforced one-order-per-active-route at the DB level,
     * but the business rule allows splitting a single order across multiple
     * active routes. The correct control is at the quantity level via
     * route_stop_items.quantity_planned, not at the stop level.
     */
    public function up(): void
    {
        // Drop the unique index on the generated column.
        // Laravel names it route_stops_active_order_id_unique by convention.
        Schema::table('route_stops', function (Blueprint $table) {
            // Drop unique index if it exists (MySQL only — SQLite doesn't have it)
            if (DB::connection()->getDriverName() !== 'sqlite') {
                $table->dropUnique('route_stops_active_order_id_unique');
            }
        });
    }

    public function down(): void
    {
        // Re-create the unique index on the generated column
        Schema::table('route_stops', function (Blueprint $table) {
            if (DB::connection()->getDriverName() !== 'sqlite') {
                $table->unique('active_order_id', 'route_stops_active_order_id_unique');
            }
        });
    }
};
