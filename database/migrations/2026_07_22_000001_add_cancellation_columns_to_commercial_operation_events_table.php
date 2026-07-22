<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercial_operation_events', function (Blueprint $table) {
            $table->string('previous_status', 20)->nullable()->after('event_type');
            $table->string('new_status', 20)->nullable()->after('previous_status');
            $table->string('reason_code', 50)->nullable()->after('new_status');
            $table->text('reason_note')->nullable()->after('reason_code');

            // Make new_date nullable for cancellation events (where it is always null)
            $table->date('new_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('commercial_operation_events', function (Blueprint $table) {
            $table->dropColumn(['previous_status', 'new_status', 'reason_code', 'reason_note']);
        });
    }
};
