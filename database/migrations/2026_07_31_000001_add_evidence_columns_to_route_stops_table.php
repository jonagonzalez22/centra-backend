<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_stops', function (Blueprint $table) {
            $table->string('signature_uri', 500)->nullable()->after('gps_lon');
            $table->json('evidence_uris')->nullable()->after('signature_uri');
        });
    }

    public function down(): void
    {
        Schema::table('route_stops', function (Blueprint $table) {
            $table->dropColumn(['evidence_uris', 'signature_uri']);
        });
    }
};
