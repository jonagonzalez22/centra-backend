<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_operation_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('store_id');
            $table->uuid('operation_id');
            $table->string('event_type', 50);
            $table->date('previous_date');
            $table->date('new_date');
            $table->string('reason', 50);
            $table->text('observation')->nullable();
            $table->uuid('user_id');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('store_id')
                ->references('id')
                ->on('stores')
                ->onDelete('restrict');

            $table->foreign('operation_id')
                ->references('id')
                ->on('commercial_operations')
                ->onDelete('cascade');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            $table->index(['store_id', 'operation_id']);
            $table->index(['store_id', 'event_type']);
            $table->index(['store_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_operation_events');
    }
};
