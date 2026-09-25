<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Models\User;
use App\Support\AppRelease;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What this build says it is (S-401, S-402).
 *
 * These are licence tests as much as version tests. AGPL §13 asks a
 * network-hosted build to offer its users the source that *corresponds to it*,
 * and for a long time this app could not do that: it was pinned at 0.1.0 with
 * no tags and linked `main` from every page. The assertions below are the
 * things that have to stay true for the offer to be honest.
 */
class AppReleaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_the_configured_version(): void
    {
        config(['app.version' => '0.2.0']);

        $this->assertSame('0.2.0', AppRelease::version());
    }

    public function test_a_named_minor_shows_its_name(): void
    {
        config(['app.version' => '0.2.3']);

        $this->assertSame('Rough Cut', AppRelease::name());
        $this->assertSame('0.2.3 “Rough Cut”', AppRelease::display());
    }

    /**
     * Desktop names are film terms, the phone's are music terms. Two
     * vocabularies so a release name says which app it belongs to without
     * anyone having to ask.
     */
    public function test_the_desktop_names_are_film_terms(): void
    {
        config(['app.version' => '0.1.0']);
        $this->assertSame('Screening', AppRelease::name());

        config(['app.version' => '1.0.0']);
        $this->assertSame('Feature', AppRelease::name());
    }

    public function test_an_unnamed_minor_shows_the_bare_number(): void
    {
        config(['app.version' => '9.9.9']);

        $this->assertNull(AppRelease::name());
        $this->assertSame('9.9.9', AppRelease::display());
    }

    /**
     * The heart of it. A link to `main` is not the corresponding source of a
     * server running a commit from three weeks ago.
     */
    public function test_the_source_url_is_pinned_to_the_commit(): void
    {
        config([
            'app.commit' => 'abc1234',
            'app.source_url' => 'https://github.com/tripsittr/SoundChex',
        ]);

        $this->assertSame(
            'https://github.com/tripsittr/SoundChex/tree/abc1234',
            AppRelease::sourceUrl(),
        );
    }

    public function test_an_unstamped_build_falls_back_to_the_repository(): void
    {
        config(['app.commit' => null, 'app.source_url' => 'https://github.com/tripsittr/SoundChex']);

        $this->assertNull(AppRelease::commit());
        $this->assertSame('https://github.com/tripsittr/SoundChex', AppRelease::sourceUrl());
    }

    /**
     * An operator running a fork owes their users *their* source. If this
     * stopped being configurable, every modified deployment would be pointing
     * its users at code that is not what they are using.
     */
    public function test_an_operator_can_point_the_offer_at_their_own_fork(): void
    {
        config([
            'app.source_url' => 'https://gitlab.example/someone/soundchex',
            'app.commit' => 'def5678',
        ]);

        $this->assertSame(
            'https://gitlab.example/someone/soundchex/tree/def5678',
            AppRelease::sourceUrl(),
        );
    }

    public function test_the_about_page_states_the_version_and_links_the_source(): void
    {
        config(['app.version' => '0.2.0', 'app.commit' => 'abc1234']);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/app/about');

        $response->assertOk();
        $response->assertSee('0.2.0 “Rough Cut”', escape: false);
        $response->assertSee('abc1234');
        $response->assertSee('https://github.com/tripsittr/SoundChex/tree/abc1234');
    }

    /**
     * A modified build has to say so: its users are owed the operator's
     * source, and a page that quietly linked upstream would be telling them
     * the wrong thing.
     */
    public function test_the_about_page_says_when_the_build_was_modified(): void
    {
        config(['app.version' => '0.2.0', 'app.commit' => 'abc1234', 'app.source_modified' => true]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/app/about')
            ->assertOk()
            ->assertSee('This build has been modified');
    }

    public function test_the_about_page_admits_when_it_has_no_commit(): void
    {
        // Silence would be worse: the reader would take the repository link
        // for the corresponding source when it is not.
        config(['app.version' => '0.2.0', 'app.commit' => null]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/app/about')
            ->assertOk()
            ->assertSee('not stamped with a commit', escape: false);
    }
}
