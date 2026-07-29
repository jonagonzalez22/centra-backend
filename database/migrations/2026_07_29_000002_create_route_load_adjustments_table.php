<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_load_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('route_stop_item_id');
            $table->uuid('user_id');
            $table->unsignedInteger('old_quantity');
            $table->unsignedInteger('new_quantity');
            $table->string('reason'); // no_stock, product_damaged, product_not_found, space_limit, other
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();
            // NO updated_at — immutable audit log

            $table->foreign('route_stop_item_id')
                ->references('id')
                ->on('route_stop_items')
                ->onDelete('cascade');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            $table->index('route_stop_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_load_adjustments');
    }
};
