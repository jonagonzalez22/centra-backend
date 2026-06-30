<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geography_localities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('province_id');
            $table->string('name');
            $table->string('zip_code', 20)->nullable();
            $table->timestamps();

            $table->foreign('province_id')
                ->references('id')
                ->on('geography_provinces')
                ->onDelete('cascade');

            $table->index('province_id');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geography_localities');
    }
};
