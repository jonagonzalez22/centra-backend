<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('cancelled_at');
            $table->uuid('closed_by')->nullable()->after('closed_at');
            $table->index('closed_by', 'purchases_closed_by_idx');
            $table->foreign('closed_by', 'purchases_closed_by_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropForeign('purchases_closed_by_fk');
            $table->dropIndex('purchases_closed_by_idx');
            $table->dropColumn(['closed_at', 'closed_by']);
        });
    }
};
