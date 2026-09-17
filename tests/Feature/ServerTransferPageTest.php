<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace Tests\Feature;

use App\Filament\Pages\ServerTransfer;
use App\Models\Profile;
use App\Models\Transfer;
use App\Models\User;
use App\Services\CurrentProfile;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The buttons on the transfer page, driven the way the browser drives them.
 *
 * The service methods behind Cancel and Delete were tested and passed while
 * neither button did anything, because nothing exercised the page itself. A
 * test that calls the service is not a test of the button that calls it.
 */
class ServerTransferPageTest extends TestCase
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

        // The page renders inside a panel, and nothing has resolved one when a
        // component is tested directly rather than reached through a URL.
        Filament::setCurrentPanel('admin');
    }

    public function test_cancelling_takes_two_clicks_and_then_cancels(): void
    {
        Http::fake(['*/transfer/requests/mine' => Http::response(['state' => 'revoked'])]);

        $transfer = $this->transfer(['state' => Transfer::RUNNING, 'token' => 'live-token']);

        $page = Livewire::test(ServerTransfer::class)
            ->call('askToConfirm', 'cancel:' . $transfer->id)
            ->assertHasNoErrors();

        // Asking is not doing. The first click only offers the second.
        $this->assertSame(Transfer::RUNNING, $transfer->fresh()->state);
        $page->assertSee('Stop it on both machines');

        $page->call('cancel', $transfer->id)->assertHasNoErrors();

        $this->assertSame(Transfer::CANCELLED, $transfer->fresh()->state);
    }

    public function test_backing_out_of_a_confirmation_changes_nothing(): void
    {
        $transfer = $this->transfer(['state' => Transfer::COMPLETE]);

        Livewire::test(ServerTransfer::class)
            ->call('askToConfirm', 'delete:' . $transfer->id)
            ->call('dismissConfirmation')
            ->assertSee('Delete')
            ->assertDontSee('Remove it');

        $this->assertNotNull(Transfer::find($transfer->id));
    }

    public function test_deleting_takes_two_clicks_and_then_deletes(): void
    {
        $transfer = $this->transfer(['state' => Transfer::COMPLETE]);

        $page = Livewire::test(ServerTransfer::class)
            ->call('askToConfirm', 'delete:' . $transfer->id)
            ->assertHasNoErrors();

        $this->assertNotNull(Transfer::find($transfer->id));

        $page->call('delete', $transfer->id)->assertHasNoErrors();

        $this->assertNull(Transfer::find($transfer->id));
    }

    public function test_a_confirmation_belongs_to_one_transfer_only(): void
    {
        // Otherwise confirming on one row would arm the button on every other,
        // and the rows are a list of near-identical addresses.
        $mine = $this->transfer(['state' => Transfer::COMPLETE]);
        $other = $this->transfer(['state' => Transfer::COMPLETE]);

        Livewire::test(ServerTransfer::class)
            ->call('askToConfirm', 'delete:' . $mine->id)
            ->assertSeeHtml('wire:click="delete(' . $mine->id . ')"')
            ->assertDontSeeHtml('wire:click="delete(' . $other->id . ')"');
    }

    public function test_the_delete_button_refuses_a_running_transfer(): void
    {
        $transfer = $this->transfer(['state' => Transfer::RUNNING]);

        Livewire::test(ServerTransfer::class)->call('delete', $transfer->id);

        $this->assertNotNull(Transfer::find($transfer->id));
    }

    public function test_the_list_offers_cancel_on_a_live_transfer_and_delete_on_a_finished_one(): void
    {
        // Rendered, not assumed: a method the page cannot reach is the failure
        // this file exists for, and so is a button that never renders.
        $this->transfer(['state' => Transfer::RUNNING]);
        $this->transfer(['state' => Transfer::FAILED]);

        Livewire::test(ServerTransfer::class)
            ->assertSee('Cancel')
            ->assertSee('Delete');
    }

    private function transfer(array $attributes = []): Transfer
    {
        return Transfer::create(array_merge([
            'source_url' => 'https://other.example',
            'token' => 'test-token',
            'wants' => ['metadata', 'files'],
            'state' => Transfer::RUNNING,
        ], $attributes));
    }
}
