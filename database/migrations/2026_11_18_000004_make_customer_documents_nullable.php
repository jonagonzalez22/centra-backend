<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->uuid('document_type_id')->nullable()->change();
            $table->string('document_number', 50)->nullable()->change();
            $table->string('document_number_normalized', 50)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->uuid('document_type_id')->nullable(false)->change();
            $table->string('document_number', 50)->nullable(false)->change();
            $table->string('document_number_normalized', 50)->nullable(false)->change();
        });
    }
};
