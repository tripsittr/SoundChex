<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilamentAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_login_is_accessible_to_guests(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_authenticated_user_is_sent_to_the_media_center(): void
    {
        $user = User::factory()->create();

        // /dashboard is a legacy entry point kept so old links and Laravel's
        // own defaults still land somewhere sensible.
        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect(route('media.home'));
    }

    public function test_media_center_requires_authentication(): void
    {
        $this->get('/app')->assertRedirect('/login');
    }
}
