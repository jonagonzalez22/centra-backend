<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_routes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('store_id');
            $table->uuid('vehicle_id');
            $table->uuid('driver_id');
            $table->date('operational_date');
            $table->string('status')->default('draft');
            $table->text('observations')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('store_id')
                ->references('id')
                ->on('stores')
                ->onDelete('restrict');

            $table->foreign('vehicle_id')
                ->references('id')
                ->on('vehicles')
                ->onDelete('restrict');

            $table->foreign('driver_id')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->onDelete('set null');

            $table->index('store_id');
            $table->index('driver_id');
            $table->index('operational_date');
            $table->index(['vehicle_id', 'operational_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_routes');
    }
};
