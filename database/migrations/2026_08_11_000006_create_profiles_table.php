<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Viewing profiles.
 *
 * A household shares one server but not one taste: everyone wants their own
 * Continue Watching, their own history, their own place in a book. A profile
 * is that separation — several to an account, chosen at the door.
 *
 * Deliberately not users: profiles have no password and no login. Switching is
 * a convenience, not a security boundary, which is exactly how every streaming
 * service treats them. Anything that needs enforcing (the admin panel) stays
 * on the user account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();

            // The account this profile belongs to. Deleting the account takes
            // its profiles and their history with it.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // A colour and initial rather than an uploaded image: it needs no
            // storage, no upload flow, and reads clearly at any size.
            $table->string('color', 16)->default('#6366f1');

            $table->string('avatar_path')->nullable();

            // Restricted profiles can't reach the admin panel and only see
            // titles at or below their rating cap.
            $table->boolean('is_kids')->default(false);

            // Highest allowed certification, e.g. "PG-13". Null means no cap.
            $table->string('max_rating', 12)->nullable();

            // The profile offered first when an account signs in.
            $table->boolean('is_default')->default(false);

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'sort_order']);
        });

        // Existing per-user data becomes per-profile. Nullable so nothing
        // written before profiles existed is orphaned — a null profile means
        // "the account's own history", which is what it was.
        foreach (['media_plays', 'reading_progress', 'annotations'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->foreignId('profile_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained()
                    ->cascadeOnDelete();
            });
        }

        // Watchlist: what someone means to get to. Distinct from the existing
        // `wishlist` flag on media_items, which marks something the household
        // doesn't own yet — this is per person and about intent to watch.
        Schema::create('watchlist_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_item_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['profile_id', 'media_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchlist_items');

        foreach (['media_plays', 'reading_progress', 'annotations'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropConstrainedForeignId('profile_id');
            });
        }

        Schema::dropIfExists('profiles');
    }
};
