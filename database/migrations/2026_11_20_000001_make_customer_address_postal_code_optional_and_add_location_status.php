<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('customer_addresses', fn (Blueprint $table) => $table->dropIndex('customer_addresses_one_delivery_per_customer'));
        }
        Schema::table('customer_addresses', function (Blueprint $table) {
            $table->string('postal_code', 20)->nullable()->change();
            $table->string('location_status', 20)->default('legacy')->after('longitude');
            $table->string('location_source', 20)->nullable()->after('location_status');
            $table->index('location_status');
        });
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement("CREATE UNIQUE INDEX customer_addresses_one_delivery_per_customer ON customer_addresses(customer_id) WHERE type = 'delivery'");
        }
    }

    public function down(): void
    {
        if (DB::table('customer_addresses')->whereNull('postal_code')->exists()) {
            throw new \RuntimeException('No se puede revertir la migración mientras existan domicilios sin código postal. Completalos o definí una migración de datos explícita.');
        }
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('customer_addresses', fn (Blueprint $table) => $table->dropIndex('customer_addresses_one_delivery_per_customer'));
        }
        Schema::table('customer_addresses', function (Blueprint $table) {
            $table->dropIndex(['location_status']);
            $table->dropColumn(['location_status', 'location_source']);
            $table->string('postal_code', 20)->nullable(false)->change();
        });
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement("CREATE UNIQUE INDEX customer_addresses_one_delivery_per_customer ON customer_addresses(customer_id) WHERE type = 'delivery'");
        }
    }
};
