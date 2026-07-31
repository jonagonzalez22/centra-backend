<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_stop_collections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('store_id');
            $table->uuid('route_stop_id');
            $table->uuid('commercial_operation_id');
            $table->uuid('store_payment_method_id');
            $table->decimal('amount', 12, 2);
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('declared_by');
            $table->timestamp('declared_at')->useCurrent();
            $table->string('status')->default('declared');
            $table->uuid('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->uuid('operation_payment_id')->nullable();
            $table->timestamps();

            $table->foreign('store_id')
                ->references('id')
                ->on('stores')
                ->onDelete('restrict');

            $table->foreign('route_stop_id')
                ->references('id')
                ->on('route_stops')
                ->onDelete('cascade');

            $table->foreign('commercial_operation_id')
                ->references('id')
                ->on('commercial_operations')
                ->onDelete('restrict');

            $table->foreign('store_payment_method_id')
                ->references('id')
                ->on('store_payment_methods')
                ->onDelete('restrict');

            $table->foreign('declared_by')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            $table->index('store_id');
            $table->index('route_stop_id');
            $table->index('commercial_operation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_stop_collections');
    }
};
