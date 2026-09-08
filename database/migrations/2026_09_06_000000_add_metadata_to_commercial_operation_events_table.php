<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercial_operation_events', function (Blueprint $table) {
            $table->json('metadata')->nullable()->after('observation');
        });
    }

    public function down(): void
    {
        Schema::table('commercial_operation_events', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
