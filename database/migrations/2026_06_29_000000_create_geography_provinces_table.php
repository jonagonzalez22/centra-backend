<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geography_provinces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('iso_code', 10);
            $table->timestamps();

            $table->index('name');
            $table->index('iso_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geography_provinces');
    }
};
