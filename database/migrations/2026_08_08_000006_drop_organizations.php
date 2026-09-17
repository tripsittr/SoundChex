<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes multi-tenancy.
 *
 * SoundChex is self-hosted: one server, one library, many user accounts — the
 * Emby/Jellyfin/Plex model. Remote access is a deployment concern (reverse
 * proxy, Cloudflare Tunnel, Tailscale), not a schema one, so scoping every row
 * to an organization bought nothing and cost a join on every query.
 *
 * Permissions stay on Spatie roles, now global rather than team-scoped.
 */
return new class extends Migration
{
    /**
     * Tables carrying an organization_id that needs removing.
     */
    private const SCOPED_TABLES = [
        'media_items',
        'media_plays',
        'collections',
        'settings',
    ];

    public function up(): void
    {
        // SQLite rebuilds the table for each dropped column and drops indexes
        // silently, so foreign keys are disabled for the rebuild.
        Schema::disableForeignKeyConstraints();

        foreach (self::SCOPED_TABLES as $table) {
            if (! Schema::hasColumn($table, 'organization_id')) {
                continue;
            }

            // Composite indexes lead with organization_id, so they must go
            // before the column they reference.
            foreach ($this->indexesOn($table, 'organization_id') as $index) {
                DB::statement("DROP INDEX IF EXISTS \"{$index}\"");
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                // SQLite validates the rebuilt table definition, so a foreign
                // key still referencing this column fails the drop even with
                // constraint enforcement disabled.
                if ($this->hasForeignKeyOn($table, 'organization_id')) {
                    $blueprint->dropForeign(["organization_id"]);
                }

                $blueprint->dropColumn('organization_id');
            });
        }

        // Settings previously scoped uniqueness to (organization_id, key);
        // with no tenants the key alone must be unique. Guarded so a partially
        // applied run can be retried.
        if (Schema::hasTable('settings')) {
            DB::statement('create unique index if not exists "settings_key_unique" on "settings" ("key")');
        }

        Schema::dropIfExists('organization_invites');
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('organizations');

        // Spatie team scoping is meaningless without tenants. The pivots key on
        // team_id, so their primary keys have to be rebuilt without it.
        foreach (['model_has_roles', 'model_has_permissions', 'roles'] as $table) {
            if (! Schema::hasColumn($table, 'team_id')) {
                continue;
            }

            foreach ($this->indexesOn($table, 'team_id') as $index) {
                DB::statement("DROP INDEX IF EXISTS \"{$index}\"");
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('team_id');
            });
        }

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Whether a table declares a foreign key on the given column.
     */
    private function hasForeignKeyOn(string $table, string $column): bool
    {
        foreach (DB::select("pragma foreign_key_list(\"{$table}\")") as $foreignKey) {
            if (($foreignKey->from ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    /**
     * Index names that reference a given column on a table.
     *
     * @return array<int, string>
     */
    private function indexesOn(string $table, string $column): array
    {
        $indexes = DB::select(
            "select name, sql from sqlite_master where type = 'index' and tbl_name = ?",
            [$table],
        );

        return collect($indexes)
            ->filter(fn ($index) => filled($index->sql) && str_contains($index->sql, "\"{$column}\""))
            ->pluck('name')
            ->all();
    }

    /**
     * Recreates the tables so the migration is reversible, but the data that
     * lived in them is gone — this is a one-way architectural change in
     * practice.
     */
    public function down(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type')->nullable();
            $table->string('logo')->nullable();
            $table->string('website')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('zip')->nullable();
            $table->string('country')->nullable();
            $table->timestamps();
        });

        Schema::create('organization_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
        });

        foreach (self::SCOPED_TABLES as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'organization_id')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->foreignId('organization_id')->nullable();
                });
            }
        }
    }
};
