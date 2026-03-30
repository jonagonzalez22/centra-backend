<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::table('users', function (Blueprint $table) {
      $table->foreignId('store_id')
        ->nullable()
        ->after('id')
        ->constrained()
        ->nullOnDelete()
        ->index();
    });
  }

  public function down(): void
  {
    Schema::table('users', function (Blueprint $table) {
      $table->dropForeign(['store_id']);
      $table->dropColumn('store_id');
    });
  }
};
