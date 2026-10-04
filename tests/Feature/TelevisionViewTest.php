<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\User;
use App\Services\MediaBrowser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Browsing lists series; episodes live on the series page.
 *
 * The Watch shelf listed every row of type Show, which meant 253 Simpsons
 * episodes sitting beside the three actual programmes — "the episodes are all
 * just out there in the open". A series row carries no file of its own, so its
 * page also had nothing to play and no way to reach them.
 */
class TelevisionViewTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function series(string $title): MediaItem
    {
        return MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Show,
            'title' => $title,
            'owned' => true,
        ]);
    }

    private function episode(MediaItem $series, string $file): MediaItem
    {
        $episode = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Show,
            'title' => pathinfo($file, PATHINFO_FILENAME),
            'file_path' => 'media/library/TV/'.$file,
            'owned' => true,
        ]);

        $episode->forceFill(['parent_id' => $series->id])->saveQuietly();

        return $episode;
    }

    public function test_browsing_shows_lists_the_series_and_not_its_episodes(): void
    {
        $series = $this->series('Jackass');
        $this->episode($series, 'Jackass - S01E01 Poo Cocktail.avi');
        $this->episode($series, 'Jackass - S01E02 Blind Driver.avi');

        $titles = app(MediaBrowser::class)
            ->grid(MediaItemType::Show)
            ->pluck('title');

        $this->assertContains('Jackass', $titles->all());
        $this->assertCount(
            1,
            $titles,
            'Episodes belong on the series page, not the shelf: '.$titles->implode(', '),
        );
    }

    public function test_a_series_page_lists_its_episodes_by_season(): void
    {
        $series = $this->series('Jackass');
        $this->episode($series, 'Jackass - S01E02 Blind Driver.avi');
        $this->episode($series, 'Jackass - S01E01 Poo Cocktail.avi');
        $this->episode($series, 'Jackass - S02E01 Mianus.avi');

        $response = $this->get(route('media.show', $series));

        $response->assertOk()
            ->assertSee('Episodes')
            ->assertSee('Season 1')
            ->assertSee('Season 2')
            ->assertSee('Poo Cocktail', false);
    }

    public function test_episodes_are_ordered_within_a_season(): void
    {
        $series = $this->series('Jackass');
        $this->episode($series, 'Jackass - S01E03 Third.avi');
        $this->episode($series, 'Jackass - S01E01 First.avi');
        $this->episode($series, 'Jackass - S01E02 Second.avi');

        $body = $this->get(route('media.show', $series))->getContent();

        $first = strpos($body, 'First');
        $second = strpos($body, 'Second');
        $third = strpos($body, 'Third');

        $this->assertNotFalse($first);
        $this->assertTrue($first < $second && $second < $third, 'Episodes should run in order.');
    }

    /**
     * An episode whose filename carries no marker must still be listed, or the
     * one page that shows it is the one page it is missing from.
     */
    public function test_an_unparseable_episode_still_appears(): void
    {
        $series = $this->series('Jackass');
        $this->episode($series, 'some bonus feature.avi');

        $this->get(route('media.show', $series))
            ->assertOk()
            ->assertSee('Other');
    }

    public function test_a_film_page_shows_no_episode_section(): void
    {
        $film = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Movie,
            'title' => 'The Goonies',
            'file_path' => 'media/library/Movies/The Goonies.mkv',
            'owned' => true,
        ]);

        $this->get(route('media.show', $film))
            ->assertOk()
            ->assertDontSee('Episodes');
    }
}
