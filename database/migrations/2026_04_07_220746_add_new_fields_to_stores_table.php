<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  /**
   * Run the migrations.
   */
  public function up(): void
  {
    Schema::table('stores', function (Blueprint $table) {

      $table->unsignedBigInteger('business_type_id')->nullable()->after('name');

      $table->foreign('business_type_id')
        ->references('id')
        ->on('business_types')
        ->onDelete('set null');


      $table->string('cuit')->after('business_type_id');
      $table->string('address')->after('cuit');
      $table->string('state')->after('address');
      $table->string('city')->after('state');
      $table->string('country')->after('city');
      $table->string('phone')->after('country');
      $table->string('url_logo')->nullable()->after('status');
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::table('stores', function (Blueprint $table) {

      $table->dropForeign(['business_type_id']);

      $table->dropColumn('business_type_id');

      $table->dropColumn([
        'cuit',
        'address',
        'state',
        'city',
        'country',
        'phone',
        'url_logo',
      ]);
    });
  }
};
