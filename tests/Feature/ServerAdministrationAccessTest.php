<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Filament\Pages\DeviceReports;
use App\Filament\Pages\MusicStatisticsPage;
use App\Filament\Pages\Network;
use App\Filament\Pages\ServerTransfer;
use App\Filament\Pages\Services;
use Spatie\Permission\Models\Permission;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Administering the library, and administering the machine under it.
 *
 * Four pages are a different kind of dangerous from the rest of the panel:
 * `Services` can stop the web server that is serving the page, and
 * `ServerTransfer` copies tens of gigabytes between machines and deletes on
 * failure. Someone trusted with the music has no reason to reach them.
 *
 * **Hiding is not refusing.** Filament resolves a page by URL whether or not
 * anything links to it, so these test the gate rather than the sidebar — a
 * page that merely omits itself from navigation is still open to anyone who
 * types the path.
 */
class ServerAdministrationAccessTest extends TestCase
{
    use RefreshDatabase;

    /** The four screens that administer the machine. */
    private const SERVER_PAGES = [
        Services::class,
        Network::class,
        ServerTransfer::class,
        DeviceReports::class,
    ];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    private function profile(bool $owner = false): Profile
    {
        return Profile::create([
            'user_id' => $this->user->id,
            'name' => $owner ? 'Owner' : 'Member',
            'is_owner' => $owner,
        ]);
    }

    private function actAs(Profile $profile): void
    {
        $this->actingAs($this->user)->withSession([
            'profile_id' => $profile->id,
            'profile_unlocked_at' => now()->timestamp,
        ]);
    }

    public function test_a_member_without_the_permission_is_refused_every_server_page(): void
    {
        $this->actAs($this->profile());

        foreach (self::SERVER_PAGES as $page) {
            $this->assertFalse($page::canAccess(), $page . ' refuses a member');
        }
    }

    public function test_a_member_granted_the_permission_reaches_them(): void
    {
        $member = $this->profile();

        $permission = Permission::firstOrCreate(
            ['name' => Profile::SERVER_ADMINISTRATION, 'guard_name' => 'web'],
        );

        $member->permissions()->attach($permission->id);

        $this->actAs($member->fresh());

        foreach (self::SERVER_PAGES as $page) {
            $this->assertTrue($page::canAccess(), $page . ' allows a granted member');
        }
    }

    public function test_the_owner_always_reaches_them(): void
    {
        // A household that could lock its own administrator out of the
        // services page would need database surgery to recover.
        $this->actAs($this->profile(owner: true));

        foreach (self::SERVER_PAGES as $page) {
            $this->assertTrue($page::canAccess(), $page . ' allows the owner');
        }
    }

    public function test_a_member_still_reaches_the_library_side_of_the_panel(): void
    {
        // The point of the split: content administration is unaffected. A
        // gate that took the whole panel with it would be a regression
        // wearing the shape of a fix.
        $member = $this->profile();

        $dashboard = Permission::firstOrCreate(['name' => 'View:Dashboard', 'guard_name' => 'web']);
        $statistics = Permission::firstOrCreate(['name' => 'View:MusicStatisticsPage', 'guard_name' => 'web']);

        $member->permissions()->attach([$dashboard->id, $statistics->id]);

        $this->actAs($member->fresh());

        $this->assertTrue(MusicStatisticsPage::canAccess(), 'statistics stays reachable');
    }

    public function test_the_pages_are_hidden_from_navigation_when_refused(): void
    {
        $this->actAs($this->profile());

        foreach (self::SERVER_PAGES as $page) {
            $this->assertFalse(
                $page::shouldRegisterNavigation(),
                $page . ' is not offered in the sidebar',
            );
        }
    }

    public function test_a_refused_page_is_not_merely_hidden(): void
    {
        // The distinction that matters. Hiding a link and refusing a request
        // are different things, and only the second is access control.
        $this->actAs($this->profile());

        $this->get('/admin/services')->assertForbidden();
        $this->get('/admin/network')->assertForbidden();
    }
}
