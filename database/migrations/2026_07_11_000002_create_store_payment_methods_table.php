<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_payment_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('store_id');
            $table->uuid('payment_method_id');
            $table->string('custom_name', 255)->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->boolean('requires_reference')->default(false);
            $table->json('account_details')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('store_id')
                ->references('id')
                ->on('stores')
                ->onDelete('cascade');

            $table->foreign('payment_method_id')
                ->references('id')
                ->on('payment_methods')
                ->onDelete('cascade');

            $table->unique(['store_id', 'payment_method_id']);
            $table->index('store_id');
            $table->index('payment_method_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_payment_methods');
    }
};
