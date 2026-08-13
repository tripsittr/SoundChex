<?php

namespace Tests\Feature;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use App\Models\Profile;
use App\Models\User;
use App\Services\ContentGate;
use App\Services\CurrentProfile;
use App\Services\MediaBrowser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Access is tested by **direct URL**, never by whether a link renders.
 *
 * Filament and Laravel routes resolve whether or not anything links to them,
 * so a test asserting that a menu item is absent proves nothing about whether
 * the page can be opened. Every case here requests the path.
 */
class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Profile $owner;

    private Profile $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->owner = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Owner',
            'is_owner' => true,
        ]);

        $this->member = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Member',
            'is_owner' => false,
        ]);

        foreach (['ViewAny:Music', 'ViewAny:User', 'Access:Settings'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    /* ---------------------------------------------- profile permissions -- */

    public function test_the_owner_can_do_everything(): void
    {
        // The owner created the household and must not be able to lock
        // themselves out of it, so the check short-circuits.
        $this->assertTrue($this->owner->can('ViewAny:Music'));
        $this->assertTrue($this->owner->can('Access:Settings'));
        $this->assertTrue($this->owner->can('Some:PermissionThatDoesNotExist'));
    }

    public function test_a_member_starts_with_nothing(): void
    {
        $this->assertFalse($this->member->can('ViewAny:Music'));
        $this->assertFalse($this->member->can('Access:Settings'));
    }

    public function test_a_grant_gives_exactly_itself(): void
    {
        $this->member->permissions()->attach(
            Permission::where('name', 'ViewAny:Music')->value('id'),
        );

        $this->assertTrue($this->member->fresh()->can('ViewAny:Music'));
        $this->assertFalse($this->member->fresh()->can('ViewAny:User'));
    }

    public function test_a_member_is_refused_by_direct_url(): void
    {
        // The point of the whole exercise: a hidden menu item is not a
        // permission, so this asks for the page itself.
        $this->actingAs($this->user);
        app(CurrentProfile::class)->switchTo($this->member->id);

        foreach (['/admin/music', '/admin/users', '/admin/settings'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_a_granted_member_reaches_only_what_was_granted(): void
    {
        $this->member->permissions()->attach(
            Permission::where('name', 'ViewAny:Music')->value('id'),
        );

        $this->actingAs($this->user);
        app(CurrentProfile::class)->switchTo($this->member->id);

        $this->get('/admin/music')->assertOk();
        $this->get('/admin/users')->assertForbidden();
        $this->get('/admin/settings')->assertForbidden();
    }

    public function test_the_dashboard_is_gated_like_every_other_panel_screen(): void
    {
        // Panel entry is open by design — the household shares one login — so
        // the dashboard was the one admin surface a capped profile could
        // open. Its widgets reported library counts, storage totals and a
        // Recently Added table listing titles above that profile's rating.
        $this->actingAs($this->user);
        app(CurrentProfile::class)->switchTo($this->member->id);

        $this->get('/admin')->assertForbidden();
    }

    public function test_dashboard_widgets_refuse_independently_of_the_page(): void
    {
        // Widgets render outside the page that hosts them, so each repeats the
        // gate rather than trusting it.
        $this->actingAs($this->user);
        app(CurrentProfile::class)->switchTo($this->member->id);

        foreach ([
            \App\Filament\Widgets\LibraryOverview::class,
            \App\Filament\Widgets\RecentActivity::class,
            \App\Filament\Widgets\GenreSplit::class,
        ] as $widget) {
            $this->assertFalse($widget::canView(), $widget . ' should refuse a capped profile');
        }
    }

    public function test_the_owner_still_reaches_the_dashboard(): void
    {
        $this->actingAs($this->user);
        app(CurrentProfile::class)->switchTo($this->owner->id);

        $this->get('/admin')->assertOk();
    }

    /* ------------------------------------------------------------- PIN --- */

    public function test_a_profile_without_a_pin_switches_freely(): void
    {
        $this->actingAs($this->user);

        $this->assertTrue(app(CurrentProfile::class)->switchTo($this->member->id));
    }

    public function test_a_pin_protected_profile_refuses_an_empty_or_wrong_pin(): void
    {
        // Without this a member could pick the owner's profile from a menu and
        // inherit its rights, making the permission a label rather than a
        // boundary.
        $this->owner->setPin('4821');
        $this->actingAs($this->user);

        $profiles = app(CurrentProfile::class);

        $this->assertFalse($profiles->switchTo($this->owner->id));
        $this->assertFalse($profiles->switchTo($this->owner->id, '0000'));
        $this->assertTrue($profiles->switchTo($this->owner->id, '4821'));
    }

    public function test_repeated_wrong_pins_lock_the_profile(): void
    {
        // A four-digit code is trivially brute-forced by someone holding the
        // phone.
        $this->owner->setPin('4821');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->owner->fresh()->verifyPin('0000');
        }

        $this->assertTrue($this->owner->fresh()->pinIsLocked());

        // Even the correct PIN is refused while locked.
        $this->assertFalse($this->owner->fresh()->verifyPin('4821'));
    }

    public function test_a_pin_is_never_stored_in_the_clear(): void
    {
        $this->owner->setPin('4821');

        $this->assertNotSame('4821', $this->owner->fresh()->pin_hash);
        $this->assertTrue($this->owner->fresh()->verifyPin('4821'));
    }

    /* ------------------------------------------------------ rating cap --- */

    public function test_a_capped_profile_cannot_see_a_higher_rated_film(): void
    {
        $film = $this->ratedMovie('Backrooms', 'R');
        $this->member->forceFill(['max_rating' => 'PG'])->save();

        $this->actingAs($this->user);
        app(CurrentProfile::class)->switchTo($this->member->id);

        $this->assertFalse(app(ContentGate::class)->allows($film));
        $this->assertSame(0, app(MediaBrowser::class)->grid(MediaItemType::Movie)->total());
    }

    public function test_a_capped_profile_is_refused_by_direct_url(): void
    {
        // Filtering browse while a direct link still plays reads as working
        // while failing — which is exactly what it did before route guards.
        $film = $this->ratedMovie('Backrooms', 'R');
        $this->member->forceFill(['max_rating' => 'PG'])->save();

        $this->actingAs($this->user);
        app(CurrentProfile::class)->switchTo($this->member->id);

        $this->get('/app/item/' . $film->id)->assertNotFound();
        $this->get('/app/item/' . $film->id . '/stream')->assertNotFound();
    }

    public function test_an_uncapped_profile_sees_everything(): void
    {
        $film = $this->ratedMovie('Backrooms', 'R');

        $this->actingAs($this->user);
        app(CurrentProfile::class)->switchTo($this->owner->id);

        $this->assertTrue(app(ContentGate::class)->allows($film));
        $this->get('/app/item/' . $film->id)->assertOk();
    }

    public function test_unrated_titles_pass_a_cap(): void
    {
        // Most music and books carry no certification; excluding them would
        // empty a kids profile rather than protect it.
        $unrated = $this->ratedMovie('Home Video', null);
        $this->member->forceFill(['max_rating' => 'PG'])->save();

        $this->actingAs($this->user);
        app(CurrentProfile::class)->switchTo($this->member->id);

        $this->assertTrue(app(ContentGate::class)->allows($unrated));
    }

    /* -------------------------------------------------------- helpers --- */

    private function ratedMovie(string $title, ?string $rating): MediaItem
    {
        $item = MediaItem::create([
            'user_id' => $this->user->id,
            'type' => MediaItemType::Movie,
            'title' => $title,
            'file_path' => '/tmp/does-not-need-to-exist.mkv',
            'owned' => true,
        ]);

        $item->movieMetadata()->create([
            'release_year' => 2026,
            'mpaa_rating' => $rating,
        ]);

        return $item->fresh();
    }
}
