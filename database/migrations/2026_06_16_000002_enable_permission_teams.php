<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

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
        $tableNames = config('permission.table_names');
        $teamForeignKey = config('permission.column_names.team_foreign_key', 'team_id');

        Schema::table($tableNames['roles'], function (Blueprint $table) use ($teamForeignKey): void {
            if (! Schema::hasColumn(config('permission.table_names.roles'), $teamForeignKey)) {
                $table->unsignedBigInteger($teamForeignKey)->nullable()->after('id');
                $table->index($teamForeignKey, 'roles_team_foreign_key_index');
            }
        });

        Schema::table($tableNames['roles'], function (Blueprint $table) use ($teamForeignKey): void {
            try {
                $table->dropUnique('roles_name_guard_name_unique');
            } catch (\Throwable $e) {
                // Index might already be replaced.
            }

            $table->unique([$teamForeignKey, 'name', 'guard_name'], 'roles_team_name_guard_name_unique');
        });

        Schema::table($tableNames['model_has_roles'], function (Blueprint $table) use ($teamForeignKey): void {
            if (! Schema::hasColumn(config('permission.table_names.model_has_roles'), $teamForeignKey)) {
                $table->unsignedBigInteger($teamForeignKey)->nullable()->after(config('permission.column_names.role_pivot_key', 'role_id'));
                $table->index($teamForeignKey, 'model_has_roles_team_foreign_key_index');
            }
        });

        Schema::table($tableNames['model_has_permissions'], function (Blueprint $table) use ($teamForeignKey): void {
            if (! Schema::hasColumn(config('permission.table_names.model_has_permissions'), $teamForeignKey)) {
                $table->unsignedBigInteger($teamForeignKey)->nullable()->after(config('permission.column_names.permission_pivot_key', 'permission_id'));
                $table->index($teamForeignKey, 'model_has_permissions_team_foreign_key_index');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableNames = config('permission.table_names');
        $teamForeignKey = config('permission.column_names.team_foreign_key', 'team_id');

        Schema::table($tableNames['roles'], function (Blueprint $table) use ($teamForeignKey): void {
            try {
                $table->dropUnique('roles_team_name_guard_name_unique');
            } catch (\Throwable $e) {
                // Ignore if missing.
            }

            $table->unique(['name', 'guard_name'], 'roles_name_guard_name_unique');

            if (Schema::hasColumn(config('permission.table_names.roles'), $teamForeignKey)) {
                $table->dropIndex('roles_team_foreign_key_index');
                $table->dropColumn($teamForeignKey);
            }
        });

        Schema::table($tableNames['model_has_roles'], function (Blueprint $table) use ($teamForeignKey): void {
            if (Schema::hasColumn(config('permission.table_names.model_has_roles'), $teamForeignKey)) {
                $table->dropIndex('model_has_roles_team_foreign_key_index');
                $table->dropColumn($teamForeignKey);
            }
        });

        Schema::table($tableNames['model_has_permissions'], function (Blueprint $table) use ($teamForeignKey): void {
            if (Schema::hasColumn(config('permission.table_names.model_has_permissions'), $teamForeignKey)) {
                $table->dropIndex('model_has_permissions_team_foreign_key_index');
                $table->dropColumn($teamForeignKey);
            }
        });
    }
};
