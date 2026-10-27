<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'commercial_groups.view',
        'commercial_groups.create',
        'commercial_groups.edit',
        'commercial_groups.delete',
    ];

    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['commercial_group_id']);
            $table->dropColumn('commercial_group_id');
        });

        Schema::dropIfExists('commercial_groups');

        $this->removePermissions();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::create('commercial_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('store_id');
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
            $table->unique(['store_id', 'name']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->uuid('commercial_group_id')->nullable()->after('customer_code');
            $table->foreign('commercial_group_id')
                ->references('id')
                ->on('commercial_groups')
                ->onDelete('set null');
        });

        $this->restorePermissions();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function removePermissions(): void
    {
        $tables = config('permission.table_names');
        $permissions = DB::table($tables['permissions'])
            ->whereIn('name', self::PERMISSIONS)
            ->pluck('id');

        if ($permissions->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($tables, $permissions): void {
            foreach (['role_has_permissions', 'model_has_permissions'] as $pivotKey) {
                DB::table($tables[$pivotKey])->whereIn('permission_id', $permissions)->delete();
            }

            DB::table($tables['permissions'])->whereIn('id', $permissions)->delete();
        });
    }

    private function restorePermissions(): void
    {
        $permissionsTable = config('permission.table_names.permissions');
        $timestamp = now();

        DB::transaction(function () use ($permissionsTable, $timestamp): void {
            DB::table($permissionsTable)->insertOrIgnore(array_map(
                fn (string $permission) => [
                    'name' => $permission,
                    'guard_name' => 'web',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
                self::PERMISSIONS
            ));
        });
    }
};
