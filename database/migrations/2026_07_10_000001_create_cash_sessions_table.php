<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('store_id');
            $table->uuid('user_id');
            $table->string('status', 20)->default('open')->index();
            $table->decimal('opening_amount', 15, 2);
            $table->decimal('expected_amount', 15, 2)->default(0);
            $table->decimal('real_amount', 15, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->foreign('store_id')
                ->references('id')
                ->on('stores')
                ->onDelete('cascade');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->index('store_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_sessions');
    }
};
