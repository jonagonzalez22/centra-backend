<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('customer_addresses')
            ->select('customer_id')
            ->where('type', 'delivery')
            ->groupBy('customer_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists()) {
            throw new \RuntimeException('No se puede aplicar la restricción: existen clientes con más de un domicilio de Entrega.');
        }

        Schema::table('customer_addresses', function (Blueprint $table) {
            if (DB::connection()->getDriverName() === 'mysql') {
                $table->uuid('delivery_customer_id')
                    ->nullable()
                    ->virtualAs("CASE WHEN `type` = 'delivery' THEN `customer_id` ELSE NULL END");
                $table->unique('delivery_customer_id', 'customer_addresses_one_delivery_per_customer');
            }
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement("CREATE UNIQUE INDEX customer_addresses_one_delivery_per_customer ON customer_addresses(customer_id) WHERE type = 'delivery'");
        }

        Schema::table('route_stops', function (Blueprint $table) {
            $table->uuid('customer_address_id')->nullable()->after('order_id');
            $table->string('destination_street', 255)->nullable()->after('customer_address_id');
            $table->string('destination_number', 20)->nullable()->after('destination_street');
            $table->string('destination_floor', 10)->nullable()->after('destination_number');
            $table->string('destination_apartment', 10)->nullable()->after('destination_floor');
            $table->string('destination_postal_code', 20)->nullable()->after('destination_apartment');
            $table->uuid('destination_locality_id')->nullable()->after('destination_postal_code');
            $table->string('destination_locality_name', 255)->nullable()->after('destination_locality_id');
            $table->decimal('destination_latitude', 10, 8)->nullable()->after('destination_locality_name');
            $table->decimal('destination_longitude', 11, 8)->nullable()->after('destination_latitude');

            $table->foreign('customer_address_id')->references('id')->on('customer_addresses')->nullOnDelete();
            $table->foreign('destination_locality_id')->references('id')->on('geography_localities')->nullOnDelete();
            $table->index('customer_address_id');
        });
    }

    public function down(): void
    {
        Schema::table('route_stops', function (Blueprint $table) {
            $table->dropForeign(['customer_address_id']);
            $table->dropForeign(['destination_locality_id']);
            $table->dropIndex(['customer_address_id']);
            $table->dropColumn([
                'customer_address_id', 'destination_street', 'destination_number', 'destination_floor',
                'destination_apartment', 'destination_postal_code', 'destination_locality_id',
                'destination_locality_name', 'destination_latitude', 'destination_longitude',
            ]);
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX customer_addresses_one_delivery_per_customer');
        } else {
            Schema::table('customer_addresses', function (Blueprint $table) {
                $table->dropUnique('customer_addresses_one_delivery_per_customer');
                $table->dropColumn('delivery_customer_id');
            });
        }
    }
};
