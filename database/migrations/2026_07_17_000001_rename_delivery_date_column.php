<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercial_operations', function (Blueprint $table) {
            $table->renameColumn('delivery_date', 'requested_delivery_date');
        });
    }

    public function down(): void
    {
        Schema::table('commercial_operations', function (Blueprint $table) {
            $table->renameColumn('requested_delivery_date', 'delivery_date');
        });
    }
};
