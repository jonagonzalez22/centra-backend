<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sku_counters', function (Blueprint $table) {
            $table->id();
            $table->uuid('store_id');
            $table->string('prefix', 4);
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->foreign('store_id')
                ->references('id')
                ->on('stores')
                ->onDelete('cascade');

            $table->unique(['store_id', 'prefix']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sku_counters');
    }
};
