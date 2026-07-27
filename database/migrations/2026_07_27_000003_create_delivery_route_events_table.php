<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_route_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('store_id');
            $table->uuid('route_id');
            $table->uuid('user_id');
            $table->string('event_type', 50);
            $table->string('from_status', 50)->nullable();
            $table->string('to_status', 50)->nullable();
            $table->string('reason', 50)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('store_id')
                ->references('id')
                ->on('stores')
                ->onDelete('restrict');

            $table->foreign('route_id')
                ->references('id')
                ->on('delivery_routes')
                ->onDelete('cascade');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            $table->index('route_id');
            $table->index('user_id');
            $table->index(['store_id', 'route_id']);
            $table->index(['store_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_route_events');
    }
};
