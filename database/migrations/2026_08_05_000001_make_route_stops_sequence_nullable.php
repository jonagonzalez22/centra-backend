<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allow NULL for cancelled stops' sequence so the UNIQUE(route_id, sequence)
     * constraint permits multiple cancelled stops in the same route.
     */
    public function up(): void
    {
        Schema::table('route_stops', function (Blueprint $table) {
            $table->integer('sequence')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('route_stops', function (Blueprint $table) {
            $table->integer('sequence')->nullable(false)->change();
        });
    }
};
