<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_rejection_reasons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('store_id')->nullable();
            $table->string('code');
            $table->string('label');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('store_id')
                ->references('id')
                ->on('stores')
                ->onDelete('cascade');

            $table->index('store_id');
            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_rejection_reasons');
    }
};
