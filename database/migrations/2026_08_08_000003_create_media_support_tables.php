<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // genre | mood | theme | instrument | custom
            $table->string('value', 100);
            $table->string('source', 20)->default('api'); // api | file | manual
            $table->timestamps();

            $table->index(['media_item_id', 'type']);
            $table->unique(['media_item_id', 'type', 'value']);
        });

        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('tmdb_id')->nullable()->index();
            $table->string('musicbrainz_artist_id', 36)->nullable();
            $table->string('headshot_url', 1024)->nullable();
            $table->timestamps();
        });

        Schema::create('media_item_person', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained()->cascadeOnDelete();
            // director | actor | author | artist | producer | creator | composer
            $table->string('role', 30);
            $table->string('character')->nullable(); // actor's character name
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->index(['media_item_id', 'role']);
        });

        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('collection_media_item', function (Blueprint $table) {
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->primary(['collection_id', 'media_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_media_item');
        Schema::dropIfExists('collections');
        Schema::dropIfExists('media_item_person');
        Schema::dropIfExists('people');
        Schema::dropIfExists('media_tags');
    }
};
