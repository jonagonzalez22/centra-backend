<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercial_operations', function (Blueprint $table) {
            $table->string('customer_display_name', 100)->nullable()->after('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('commercial_operations', function (Blueprint $table) {
            $table->dropColumn('customer_display_name');
        });
    }
};
