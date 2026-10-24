<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('store_id');
            $table->uuid('purchase_id');
            $table->string('type', 30);
            $table->string('number', 100);
            $table->date('issued_at');
            $table->decimal('total_amount', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by');
            $table->timestamps();

            $table->foreign('store_id', 'pd_store_fk')->references('id')->on('stores')->restrictOnDelete();
            $table->foreign('purchase_id', 'pd_purchase_fk')->references('id')->on('purchases')->restrictOnDelete();
            $table->foreign('created_by', 'pd_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->index(['store_id', 'purchase_id'], 'pd_store_purchase_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_documents');
    }
};
