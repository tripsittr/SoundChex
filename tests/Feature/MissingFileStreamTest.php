<?php

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A catalogued row whose file has not arrived is not a missing row.
 *
 * A transfer imports the catalogue in one go and then copies files for hours,
 * so the whole library is browsable and most of it is unplayable. Every one of
 * those failed with a bare 404, which the browser reports as
 * `MEDIA_ERR_SRC_NOT_SUPPORTED` — so the app blamed the codec for a file it
 * had never been sent, and the owner hit it on their phone.
 */
class MissingFileStreamTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_catalogued_row_with_no_file_says_it_has_not_arrived(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $item = MediaItem::create([
            'user_id' => $user->id,
            'title' => 'Come Out and Play',
            'type' => MediaItemType::Music,
            'file_path' => 'media/library/Music/The Offspring/Smash/3066 Come Out and Play.mp3',
            'processing_status' => 'complete',
        ]);

        $this->get('/app/item/' . $item->id . '/stream')
            ->assertStatus(409);

        // 409 rather than 404 is the whole point: the row is real and the
        // condition is temporary, so the player can tell "not here yet" from
        // "no such thing" and stop blaming the codec.
    }

    public function test_a_row_with_no_path_at_all_is_still_a_404(): void
    {
        // Nothing was ever promised for this one, so "not found" is honest.
        $user = User::factory()->create();
        $this->actingAs($user);

        $item = MediaItem::create([
            'user_id' => $user->id,
            'title' => 'Nothing here',
            'type' => MediaItemType::Music,
            'processing_status' => 'complete',
        ]);

        $this->get('/app/item/' . $item->id . '/stream')->assertNotFound();
    }
}
