<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('store_id');
            $table->string('business_name', 150);
            $table->string('tax_id', 100)->nullable();
            $table->string('contact_name', 150)->nullable();
            $table->string('phone', 100)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('address', 255)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->foreign('store_id')->references('id')->on('stores')->restrictOnDelete();
            $table->unique(['store_id', 'tax_id']);
            $table->index(['store_id', 'is_active']);
            $table->index(['store_id', 'business_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
