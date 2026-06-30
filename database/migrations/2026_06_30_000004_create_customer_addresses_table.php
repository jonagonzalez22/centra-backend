<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->uuid('locality_id');
            $table->string('street', 255);
            $table->string('number', 20);
            $table->string('floor', 10)->nullable();
            $table->string('apartment', 10)->nullable();
            $table->string('postal_code', 20);
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('type', 20)->default('other');
            $table->boolean('is_main')->default(false);
            $table->text('observations')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
            $table->foreign('locality_id')->references('id')->on('geography_localities')->onDelete('cascade');

            $table->index('customer_id');
            $table->index('locality_id');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
    }
};
