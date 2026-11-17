<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Decision (Nov 2026): only Admin and Customer roles for now. Remove the staff roles created
 * in M0/M1; users holding them lose only that role (Admin covers everything).
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('roles')->whereIn('name', ['manager', 'sales', 'catalog_manager'])->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('role_has_permissions')->whereIn('role_id', $ids)->delete();
        DB::table('model_has_roles')->whereIn('role_id', $ids)->delete();
        DB::table('roles')->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Roles can be re-created from System → Roles if needed.
    }
};
