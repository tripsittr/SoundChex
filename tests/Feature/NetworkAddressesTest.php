<?php

namespace Tests\Feature;

use App\Services\NetworkAddresses;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The addresses this server tells clients about.
 *
 * A LAN address is handed out by whichever router the server is currently on,
 * so it stops resolving the moment the machine joins a different network —
 * moving between wifi and a hotspot is enough. The stored list kept serving the
 * old address and never mentioned the new one, so every client was pointed
 * somewhere that no longer existed while the server ran fine.
 */
class NetworkAddressesTest extends TestCase
{
    use RefreshDatabase;

    private function addresses(): NetworkAddresses
    {
        return new NetworkAddresses(app(SettingsService::class));
    }

    public function test_detected_addresses_are_offered_when_nothing_is_stored(): void
    {
        $this->assertNotEmpty($this->addresses()->all());
    }

    public function test_a_newly_detected_address_appears_even_though_it_was_never_stored(): void
    {
        $service = $this->addresses();

        // A list from before the server moved networks: it cannot contain the
        // address the machine has now.
        $service->save(['http://192.168.1.205:8000']);

        $all = $service->all();

        foreach ($service->detected() as $current) {
            $this->assertContains(
                $current,
                $all,
                'an address the machine currently has must be offered to clients',
            );
        }
    }

    public function test_detected_addresses_are_ranked_before_stored_ones(): void
    {
        $service = $this->addresses();

        $service->save(['http://192.168.1.205:8000']);

        $all = $service->all();
        $detected = $service->detected();

        // The client races these, so a dead address costs a timeout rather than
        // a failure — but the ones known to be current should be tried first.
        $this->assertSame(
            $detected[0],
            $all[0],
            'a currently-detected address leads the list',
        );
    }

    public function test_a_stored_address_is_kept_rather_than_discarded(): void
    {
        $service = $this->addresses();

        // Still offered: the server cannot see a public tunnel or a route that
        // only works from outside the house, so dropping what it cannot verify
        // would throw away the only address a remote device can use.
        $service->save(['https://library.example.com']);

        $this->assertContains('https://library.example.com', $service->all());
    }

    public function test_the_list_has_no_duplicates(): void
    {
        $service = $this->addresses();
        $detected = $service->detected();

        $service->save($detected);

        $all = $service->all();

        $this->assertSame(array_values(array_unique($all)), $all);
    }
}
