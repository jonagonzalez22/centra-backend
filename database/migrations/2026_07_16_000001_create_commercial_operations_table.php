<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('store_id');
            $table->uuid('branch_id')->nullable()->index();
            $table->uuid('user_id');
            $table->uuid('customer_id')->nullable();
            $table->string('operation_number', 20);
            $table->string('type', 20);
            $table->string('status', 20)->default('pending');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->date('delivery_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('store_id')
                ->references('id')
                ->on('stores')
                ->onDelete('restrict');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            $table->foreign('customer_id')
                ->references('id')
                ->on('customers')
                ->onDelete('set null');

            $table->index('store_id');
            $table->index(['store_id', 'type', 'status']);
            $table->index('customer_id');
            $table->index('delivery_date');
            $table->unique(['store_id', 'operation_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_operations');
    }
};
