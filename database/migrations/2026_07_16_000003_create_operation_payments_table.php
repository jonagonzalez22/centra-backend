<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('operation_id');
            $table->uuid('store_payment_method_id');
            $table->decimal('amount', 12, 2);
            $table->string('reference')->nullable();
            $table->json('payment_details')->nullable();
            $table->timestamps();

            $table->foreign('operation_id')
                ->references('id')
                ->on('commercial_operations')
                ->onDelete('cascade');

            $table->foreign('store_payment_method_id')
                ->references('id')
                ->on('store_payment_methods')
                ->onDelete('restrict');

            $table->index('operation_id');
            $table->index('store_payment_method_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_payments');
    }
};
