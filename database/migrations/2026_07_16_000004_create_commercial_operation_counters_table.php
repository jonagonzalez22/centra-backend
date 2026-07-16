<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_operation_counters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('store_id');
            $table->string('type', 20);
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->foreign('store_id')
                ->references('id')
                ->on('stores')
                ->onDelete('cascade');

            $table->unique(['store_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_operation_counters');
    }
};
