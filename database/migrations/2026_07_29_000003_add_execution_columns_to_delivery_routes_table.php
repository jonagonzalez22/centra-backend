<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_routes', function (Blueprint $table) {
            $table->timestamp('loaded_at')->nullable()->after('requires_recalculation');
            $table->uuid('loaded_by')->nullable()->after('loaded_at');
            $table->timestamp('dispatched_at')->nullable()->after('loaded_by');
            $table->uuid('dispatched_by')->nullable()->after('dispatched_at');

            $table->foreign('loaded_by')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            $table->foreign('dispatched_by')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_routes', function (Blueprint $table) {
            $table->dropForeign(['loaded_by']);
            $table->dropForeign(['dispatched_by']);
            $table->dropColumn(['loaded_at', 'loaded_by', 'dispatched_at', 'dispatched_by']);
        });
    }
};
