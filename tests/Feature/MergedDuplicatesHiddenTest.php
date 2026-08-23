<?php

namespace Tests\Feature;

use App\Enums\DuplicateStatus;
use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\User;
use App\Services\ContentGate;
use App\Services\CurrentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A duplicate that has been resolved should stop appearing in the library.
 *
 * `DuplicateDetector::merge()` keeps the duplicate row deliberately — play
 * history and ratings live on it — and repoints it at the surviving file.
 * Nothing hid it afterwards, so a file catalogued nine times still showed nine
 * times once its duplicates were merged, and resolving them looked like it had
 * achieved nothing.
 */
class MergedDuplicatesHiddenTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $owner = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Owner',
            'is_owner' => true,
        ]);

        $this->actingAs($this->user);
        app(CurrentProfile::class)->switchTo($owner->id);
    }

    public function test_a_merged_duplicate_is_not_in_the_library(): void
    {
        $kept = $this->item('Jackass Number Two');
        $merged = $this->item('Jackass Number Two', DuplicateStatus::Merged);

        $visible = app(ContentGate::class)->apply(MediaItem::query())->pluck('id');

        $this->assertTrue($visible->contains($kept->id), 'The surviving copy vanished.');
        $this->assertFalse(
            $visible->contains($merged->id),
            'A merged duplicate is still listed, so resolving duplicates changes nothing on screen.',
        );
    }

    public function test_a_duplicate_still_awaiting_review_is_left_visible(): void
    {
        // Nobody has decided about it yet. Hiding it would remove a file from
        // the library on nothing more than a suspicion.
        $pending = $this->item('Karma Police', DuplicateStatus::Pending);

        $visible = app(ContentGate::class)->apply(MediaItem::query())->pluck('id');

        $this->assertTrue($visible->contains($pending->id));
    }

    public function test_a_pair_deliberately_kept_stays_visible(): void
    {
        // The user chose to keep both. That decision is final.
        $kept = $this->item('Karma Police', DuplicateStatus::Kept);

        $visible = app(ContentGate::class)->apply(MediaItem::query())->pluck('id');

        $this->assertTrue($visible->contains($kept->id));
    }

    public function test_an_ordinary_item_is_untouched(): void
    {
        $plain = $this->item('Backrooms');

        $visible = app(ContentGate::class)->apply(MediaItem::query())->pluck('id');

        $this->assertTrue($visible->contains($plain->id));
    }

    private function item(string $title, ?DuplicateStatus $status = null): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Movie,
            'title' => $title,
            'file_path' => 'media/library/Movies/' . $title . '.avi',
        ]);

        if ($status !== null) {
            $item->forceFill(['duplicate_status' => $status])->saveQuietly();
        }

        return $item->fresh();
    }
}
