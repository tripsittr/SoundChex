<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use App\Services\CurrentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The floor on the management panel, and the two tiers above it.
 *
 * The panel used to admit everyone and refuse page by page — and most pages
 * asked for permissions that were never created, so they were owner-only by
 * accident. Now there are two explicit tiers:
 *
 *   - Library administration: into the panel, the catalogue and its settings.
 *   - Server administration: the machine underneath, and it implies the first.
 *
 * A member or uploader, holding neither, does not reach the panel at all.
 */
class LibraryAdministrationAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // The permissions the migration creates. RefreshDatabase runs
        // migrations, so both exist here.
        $this->user = User::factory()->create();
    }

    private function profileWith(string ...$permissions): Profile
    {
        $profile = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'P' . fake()->unique()->numberBetween(1, 99999),
        ]);

        foreach ($permissions as $name) {
            $profile->permissions()->attach(Permission::where('name', $name)->value('id'));
        }

        return $profile;
    }

    private function actingAsProfile(Profile $profile): void
    {
        $this->actingAs($this->user);
        app(CurrentProfile::class)->switchTo($profile->id);
    }

    public function test_a_profile_with_no_grant_cannot_administer_the_library(): void
    {
        $this->assertFalse($this->profileWith()->canAdministerLibrary());
    }

    public function test_library_administration_admits_the_library(): void
    {
        $this->assertTrue(
            $this->profileWith(Profile::LIBRARY_ADMINISTRATION)->canAdministerLibrary(),
        );
    }

    public function test_server_administration_implies_library_administration(): void
    {
        // Someone trusted with the machine is trusted with the library on it —
        // otherwise a server admin would be locked out of the content pages.
        $this->assertTrue(
            $this->profileWith(Profile::SERVER_ADMINISTRATION)->canAdministerLibrary(),
        );
    }

    public function test_the_owner_always_can(): void
    {
        $owner = Profile::create([
            'user_id' => $this->user->id,
            'name' => 'Owner',
            'is_owner' => true,
        ]);

        $this->assertTrue($owner->canAdministerLibrary());
    }

    public function test_a_member_is_refused_the_admin_panel(): void
    {
        $this->actingAsProfile($this->profileWith());

        // The dashboard is the panel's landing page; a member lands nowhere.
        $this->get('/admin')->assertForbidden();
    }

    public function test_a_library_admin_reaches_the_content_pages(): void
    {
        $this->actingAsProfile($this->profileWith(Profile::LIBRARY_ADMINISTRATION));

        $this->get('/admin/metadata-settings')->assertOk();
        $this->get('/admin/music')->assertOk();
    }

    public function test_a_library_admin_is_refused_the_server_pages(): void
    {
        // The line that matters: content yes, machine no.
        $this->actingAsProfile($this->profileWith(Profile::LIBRARY_ADMINISTRATION));

        $this->get('/admin/services')->assertForbidden();
        $this->get('/admin/integrations')->assertForbidden();
    }

    public function test_a_server_admin_reaches_both(): void
    {
        $this->actingAsProfile($this->profileWith(Profile::SERVER_ADMINISTRATION));

        $this->get('/admin/metadata-settings')->assertOk();
        $this->get('/admin/services')->assertOk();
    }

    public function test_the_permission_exists_to_be_granted(): void
    {
        // Access is per profile, not per role — Profile::can() reads the
        // profile_permissions relation and never consults roles. So the
        // migration's job is only to make the permission exist; granting it is
        // the owner's, through the profile screen.
        $this->assertTrue(
            Permission::where('name', Profile::LIBRARY_ADMINISTRATION)->where('guard_name', 'web')->exists(),
        );
    }
}
