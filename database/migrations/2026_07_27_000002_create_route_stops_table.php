<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_stops', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('route_id');
            $table->uuid('order_id');
            $table->unsignedInteger('sequence');
            $table->string('status')->default('pending');
            $table->text('logistics_notes')->nullable();
            $table->timestamps();

            $table->foreign('route_id')
                ->references('id')
                ->on('delivery_routes')
                ->onDelete('cascade');

            $table->foreign('order_id')
                ->references('id')
                ->on('commercial_operations')
                ->onDelete('restrict');

            $table->unique(['route_id', 'sequence']);

            // Generated column for active order uniqueness
            // MySQL 8.0: only one active (non-cancelled) stop per order
            // SQLite does not support stored generated columns — uniqueness enforced at app level
            if (DB::connection()->getDriverName() !== 'sqlite') {
                $table->string('active_order_id')
                    ->storedAs("CASE WHEN status != 'cancelled' THEN order_id ELSE NULL END")
                    ->nullable();
                $table->unique('active_order_id');
            }

            $table->index('route_id');
            $table->index('order_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_stops');
    }
};
