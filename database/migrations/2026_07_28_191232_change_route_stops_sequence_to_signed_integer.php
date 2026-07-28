<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Change sequence from unsigned to signed integer.
     *
     * The reorder algorithm uses temporary negative sequences to avoid
     * UNIQUE(route_id, sequence) constraint conflicts. MySQL rejects
     * negative values on unsigned columns.
     */
    public function up(): void
    {
        Schema::table('route_stops', function (Blueprint $table) {
            $table->integer('sequence')->change();
        });
    }

    public function down(): void
    {
        Schema::table('route_stops', function (Blueprint $table) {
            $table->unsignedInteger('sequence')->change();
        });
    }
};
