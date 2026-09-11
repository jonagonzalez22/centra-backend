<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->string('status', 32)->default('open')->change();
        });
    }

    public function down(): void
    {
        DB::table('cash_sessions')
            ->where('status', 'pending_reconciliation')
            ->update(['status' => 'open']);

        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->string('status', 20)->default('open')->change();
        });
    }
};
