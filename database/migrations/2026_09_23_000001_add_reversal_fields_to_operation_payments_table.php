<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operation_payments', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->after('registered_by');
            $table->timestamp('reversed_at')->nullable()->after('status');
            $table->uuid('reversed_by')->nullable()->after('reversed_at')->index();

            $table->foreign('reversed_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['cash_session_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('operation_payments', function (Blueprint $table) {
            $table->dropForeign(['reversed_by']);
            $table->dropIndex(['reversed_by']);
            $table->dropIndex(['cash_session_id', 'status']);
            $table->dropColumn(['status', 'reversed_at', 'reversed_by']);
        });
    }
};
