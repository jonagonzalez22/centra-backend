<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercial_operation_events', function (Blueprint $table) {
            $table->date('previous_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('commercial_operation_events')->whereNull('previous_date')->exists()) {
            throw new RuntimeException(
                'No se puede restaurar previous_date como obligatorio mientras existan eventos sin fecha previa.'
            );
        }

        Schema::table('commercial_operation_events', function (Blueprint $table) {
            $table->date('previous_date')->nullable(false)->change();
        });
    }
};
