<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_stop_items', function (Blueprint $table) {
            $table->unsignedInteger('quantity_released_for_extra_sale')
                ->default(0)
                ->after('quantity_delivered');
        });

        Schema::table('delivery_rejection_reasons', function (Blueprint $table) {
            $table->boolean('suggest_extra_sale')
                ->default(false)
                ->after('is_active');
        });

        DB::table('delivery_rejection_reasons')
            ->whereNull('store_id')
            ->whereIn('code', [
                'customer_absent',
                'wrong_address',
                'access_issue',
                'rejected_by_customer',
                'no_payment',
            ])
            ->update(['suggest_extra_sale' => true]);
    }

    public function down(): void
    {
        Schema::table('delivery_rejection_reasons', function (Blueprint $table) {
            $table->dropColumn('suggest_extra_sale');
        });

        Schema::table('route_stop_items', function (Blueprint $table) {
            $table->dropColumn('quantity_released_for_extra_sale');
        });
    }
};
