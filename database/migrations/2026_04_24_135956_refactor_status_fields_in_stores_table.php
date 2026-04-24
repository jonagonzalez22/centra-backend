<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
  public function up(): void
  {
    Schema::table('stores', function (Blueprint $table) {
      $table->boolean('is_active')->default(true)->after('email');
      $table->string('inactive_reason')->nullable()->after('is_active');
      $table->timestamp('inactive_at')->nullable()->after('inactive_reason');
    });

    DB::statement("
      UPDATE stores
      SET is_active = CASE 
        WHEN status = 'active' THEN 1
        ELSE 0
      END
    ");

    Schema::table('stores', function (Blueprint $table) {
      $table->dropColumn('status');
    });
  }

  public function down(): void
  {
    Schema::table('stores', function (Blueprint $table) {
      $table->enum('status', ['active', 'inactive'])->default('active')->after('email');
    });

    DB::statement("
      UPDATE stores
      SET status = CASE
        WHEN is_active = 1 THEN 'active'
        ELSE 'inactive'
      END
    ");

    Schema::table('stores', function (Blueprint $table) {
      $table->dropColumn([
        'is_active',
        'inactive_reason',
        'inactive_at'
      ]);
    });
  }
};
