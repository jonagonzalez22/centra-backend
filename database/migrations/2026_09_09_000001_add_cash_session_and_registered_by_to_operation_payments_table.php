<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operation_payments', function (Blueprint $table) {
            $table->uuid('cash_session_id')->nullable()->after('store_payment_method_id')->index();
            $table->uuid('registered_by')->nullable()->after('cash_session_id')->index();

            $table->foreign('cash_session_id')->references('id')->on('cash_sessions')->nullOnDelete();
            $table->foreign('registered_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('operation_payments', function (Blueprint $table) {
            $table->dropForeign(['cash_session_id']);
            $table->dropForeign(['registered_by']);
            $table->dropColumn(['cash_session_id', 'registered_by']);
        });
    }
};
