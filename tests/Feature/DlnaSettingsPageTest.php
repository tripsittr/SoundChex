<?php
namespace Tests\Feature;
use App\Filament\Pages\Network;
use App\Models\Profile;
use App\Models\User;
use App\Services\Dlna\DlnaSettings;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

/** Turning DLNA on from the admin panel (S-7). */
class DlnaSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Profile::create(['user_id' => $this->user->id, 'name' => 'Owner', 'is_owner' => true]);
        $this->actingAs($this->user);
        Filament::setCurrentPanel('admin');
    }

    public function test_switching_it_on_without_a_profile_is_refused(): void
    {
        // The profile IS the access control here — quietly picking one would
        // be picking who can see what.
        Livewire::test(Network::class)
            ->set('dlnaEnabled', true)
            ->set('dlnaProfile', null)
            ->call('saveDlna');

        $this->assertFalse((bool) app(SettingsService::class)->get(DlnaSettings::ENABLED));
    }

    public function test_switching_it_on_with_a_profile_is_saved(): void
    {
        $profile = Profile::where('user_id', $this->user->id)->first();

        Livewire::test(Network::class)
            ->set('dlnaEnabled', true)
            ->set('dlnaProfile', $profile->id)
            ->set('dlnaName', 'Front Room')
            ->call('saveDlna');

        $dlna = app(DlnaSettings::class);
        $this->assertTrue($dlna->shouldRun());
        $this->assertSame($profile->id, $dlna->profile()?->id);
        $this->assertSame('Front Room', $dlna->friendlyName());
    }

    public function test_another_accounts_profiles_are_not_offered(): void
    {
        // Profiles belong to accounts; offering another household's as the
        // face of the living-room TV would be a leak.
        $other = User::factory()->create();
        Profile::create(['user_id' => $other->id, 'name' => 'Someone Else', 'is_owner' => true]);

        $options = Livewire::test(Network::class)->instance()->profileOptions();

        $this->assertNotContains('Someone Else', $options);
    }
}
