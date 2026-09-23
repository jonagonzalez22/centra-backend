<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $this->renamePermission('sales.view', 'sales_history.view');
        $this->renamePermission('sales.print', 'sales_history.print');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $this->renamePermission('sales_history.view', 'sales.view');
        $this->renamePermission('sales_history.print', 'sales.print');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function renamePermission(string $from, string $to): void
    {
        $tables = config('permission.table_names');
        $permissionsTable = $tables['permissions'];
        $oldPermission = DB::table($permissionsTable)->where('name', $from)->first();

        if (! $oldPermission) {
            return;
        }

        $newPermission = DB::table($permissionsTable)->where('name', $to)->first();

        if (! $newPermission) {
            DB::table($permissionsTable)->where('id', $oldPermission->id)->update(['name' => $to]);

            return;
        }

        DB::transaction(function () use ($tables, $oldPermission, $newPermission): void {
            foreach (['role_has_permissions', 'model_has_permissions'] as $pivotKey) {
                $pivotTable = $tables[$pivotKey];
                $rows = DB::table($pivotTable)->where('permission_id', $oldPermission->id)->get();

                foreach ($rows as $row) {
                    $identity = collect(get_object_vars($row))->except('permission_id')->all();
                    $target = DB::table($pivotTable)->where('permission_id', $newPermission->id);

                    foreach ($identity as $column => $value) {
                        $target->where($column, $value);
                    }

                    if ($target->exists()) {
                        DB::table($pivotTable)->where('permission_id', $oldPermission->id)
                            ->where($identity)
                            ->delete();
                    } else {
                        DB::table($pivotTable)->where('permission_id', $oldPermission->id)
                            ->where($identity)
                            ->update(['permission_id' => $newPermission->id]);
                    }
                }
            }

            DB::table($tables['permissions'])->where('id', $oldPermission->id)->delete();
        });
    }
};
