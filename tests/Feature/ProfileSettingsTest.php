<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Settings belonging to whoever is watching.
 *
 * Distinct from the admin panel, which configures the server for everyone.
 * These are per profile, so two people sharing a device do not share an
 * autoplay preference or each other's PIN.
 */
class ProfileSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function actingProfile(array $attributes = []): array
    {
        $user = User::factory()->create();
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Owner',
            'is_owner' => true,
            'is_default' => true,
            ...$attributes,
        ]);

        $this->actingAs($user);

        // Unlocked, because these tests are about the settings themselves. A
        // profile with a PIN is otherwise gated by RequireProfileUnlock, which
        // is covered by its own tests.
        $this->withSession([
            'profile_id' => $profile->id,
            'profile_unlocked_at' => now()->timestamp,
        ]);

        return [$user, $profile];
    }

    public function test_defaults_apply_to_a_profile_that_has_never_saved(): void
    {
        [, $profile] = $this->actingProfile();

        $this->assertTrue($profile->preference('autoplay_next'));

        // Off until asked for: the OS prompt is shown once per install, and
        // asking before anyone wants notifications gets them denied for good.
        $this->assertFalse($profile->preference('notifications_enabled'));
    }

    public function test_a_preference_is_saved(): void
    {
        [, $profile] = $this->actingProfile();

        $this->patch(route('media.settings.update'), [
            'autoplay_next' => '0',
            'crossfade_seconds' => 5,
        ])->assertRedirect();

        $profile->refresh();

        $this->assertFalse($profile->preference('autoplay_next'));
        $this->assertSame(5, $profile->preference('crossfade_seconds'));
    }

    public function test_an_unticked_toggle_turns_off(): void
    {
        [, $profile] = $this->actingProfile();

        $profile->setPreferences(['autoplay_next' => true]);

        // A checkbox sends nothing when unticked. Without treating absence as
        // false a toggle could be turned on and never turned off again.
        $this->patch(route('media.settings.update'), []);

        $this->assertFalse($profile->fresh()->preference('autoplay_next'));
    }

    public function test_unknown_preferences_are_rejected(): void
    {
        [, $profile] = $this->actingProfile();

        $profile->setPreferences(['not_a_real_setting' => 'anything']);

        // This column is written from a client; an unbounded JSON blob is
        // somewhere to hide arbitrary data.
        $this->assertArrayNotHasKey('not_a_real_setting', $profile->fresh()->preferences ?? []);
    }

    public function test_crossfade_is_bounded(): void
    {
        $this->actingProfile();

        $this->patch(route('media.settings.update'), ['crossfade_seconds' => 99])
            ->assertSessionHasErrors('crossfade_seconds');
    }

    public function test_a_pin_can_be_set(): void
    {
        [, $profile] = $this->actingProfile();

        $this->patch(route('media.settings.pin'), [
            'pin' => '1234',
            'pin_confirmation' => '1234',
        ])->assertRedirect();

        $this->assertTrue($profile->fresh()->requiresPin());
    }

    public function test_changing_a_pin_requires_the_current_one(): void
    {
        [, $profile] = $this->actingProfile(['pin_hash' => Hash::make('1111')]);

        // Otherwise anyone reaching an unlocked session could replace the PIN
        // protecting a profile they do not own.
        $this->patch(route('media.settings.pin'), [
            'current_pin' => '9999',
            'pin' => '2222',
            'pin_confirmation' => '2222',
        ])->assertSessionHasErrors('current_pin');

        $this->assertTrue($profile->fresh()->verifyPin('1111'));
    }

    public function test_a_pin_can_be_removed_with_the_current_one(): void
    {
        [, $profile] = $this->actingProfile(['pin_hash' => Hash::make('1111')]);

        $this->patch(route('media.settings.pin'), [
            'current_pin' => '1111',
            'pin' => '',
        ])->assertRedirect();

        $this->assertFalse($profile->fresh()->requiresPin());
    }

    public function test_a_mismatched_confirmation_is_refused(): void
    {
        $this->actingProfile();

        $this->patch(route('media.settings.pin'), [
            'pin' => '1234',
            'pin_confirmation' => '4321',
        ])->assertSessionHasErrors('pin');
    }

    public function test_one_profile_does_not_see_another_profiles_settings(): void
    {
        [$user, $profile] = $this->actingProfile();

        $other = Profile::create(['user_id' => $user->id, 'name' => 'Other']);
        $other->setPreferences(['autoplay_next' => false]);

        $profile->setPreferences(['autoplay_next' => true]);

        $this->assertTrue($profile->fresh()->preference('autoplay_next'));
        $this->assertFalse($other->fresh()->preference('autoplay_next'));
    }
}
