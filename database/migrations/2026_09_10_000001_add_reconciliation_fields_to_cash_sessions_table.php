<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->date('business_date')->nullable()->after('status')->index();
            $table->decimal('declared_amount', 15, 2)->nullable()->after('real_amount');
            $table->timestamp('submitted_at')->nullable()->after('opened_at');
            $table->text('declaration_notes')->nullable()->after('notes');
            $table->uuid('closed_by')->nullable()->after('user_id')->index();
            $table->text('reconciliation_notes')->nullable()->after('declaration_notes');

            $table->foreign('closed_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['store_id', 'user_id', 'business_date', 'status'], 'cash_sessions_operational_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->dropForeign(['closed_by']);
            $table->dropIndex('cash_sessions_operational_lookup');
            $table->dropColumn([
                'business_date',
                'declared_amount',
                'submitted_at',
                'declaration_notes',
                'closed_by',
                'reconciliation_notes',
            ]);
        });
    }
};
