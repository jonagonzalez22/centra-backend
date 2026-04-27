<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::table('stores', function (Blueprint $table) {
      // Make it nullable so existing stores don't break during migration
      $table->foreignUuid('plan_id')
        ->after('business_type_id') // Lo ubicamos cerca de los otros IDs
        ->nullable()
        ->constrained('plans')
        ->onDelete('set null'); // If a plan is deleted, the store will be left without a plan but the store won't be deleted
      $table->timestamp('trial_ends_at')->after('plan_id')->nullable();
    });
  }

  public function down(): void
  {
    Schema::table('stores', function (Blueprint $table) {
      $table->dropForeign(['plan_id']);
      $table->dropColumn(['plan_id', 'trial_ends_at']);
    });
  }
};
