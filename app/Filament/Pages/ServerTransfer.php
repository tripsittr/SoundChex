<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\RestrictsToServerAdmins;
use App\Jobs\RunTransferJob;
use App\Models\Transfer;
use App\Models\TransferRequest;
use App\Services\TransferApprovals;
use App\Services\TransferReceiver;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Hash;
use UnitEnum;

/**
 * Moving this library to or from another machine.
 *
 * Both halves live on one page because both machines run this code: the
 * server being copied approves requests here, and the one doing the copying
 * starts them here. Which half matters depends on which end you are at.
 */
class ServerTransfer extends Page
{
    use RestrictsToServerAdmins;


    protected string $view = 'filament.pages.server-transfer';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Server transfer';

    protected static ?int $navigationSort = 30;

    /* ------------------------------------------------- receiving side ---- */

    public string $sourceUrl = '';

    /** @var array<string, bool> */
    public array $wants = [
        'metadata' => true,
        'files' => true,
        'profiles' => true,
        'settings' => false,
    ];

    public string $password = '';

    /* --------------------------------------------------------- state ---- */

    public array $pending = [];

    public array $active = [];

    public array $transfers = [];

    /**
     * Which destructive button is waiting for a second click, as `action:id`.
     *
     * A round trip rather than `wire:confirm`'s native dialog. Those two
     * buttons were the only use of that directive in the application, and both
     * did nothing when clicked while the methods behind them worked — so the
     * confirmation is done the way every button on this page that does work is
     * done, and a test can drive it without a browser.
     */
    public ?string $confirming = null;

    public function askToConfirm(string $key): void
    {
        $this->confirming = $key;
    }

    public function dismissConfirmation(): void
    {
        $this->confirming = null;
    }

    public function mount(): void
    {
        $this->refresh();
    }

    public function refresh(): void
    {
        $approvals = app(TransferApprovals::class);

        $this->pending = $approvals->pending()->map(fn (TransferRequest $r) => [
            'id' => $r->id,
            'ip' => $r->ip,
            'device' => $r->device_name ?: 'unnamed',
            'platform' => $r->platform ?: 'unknown',
            'wants' => $r->wantsLabel(),
            'code' => $r->code,
            'when' => $r->created_at?->diffForHumans(),
        ])->all();

        $this->active = $approvals->active()->map(fn (TransferRequest $r) => [
            'id' => $r->id,
            'ip' => $r->ip,
            'device' => $r->device_name ?: 'unnamed',
            'wants' => $r->wantsLabel(),
            'since' => $r->approved_at?->diffForHumans(),
            // Reported by the receiver, which is the only machine that knows.
            // Null until the first report arrives; `heard` going quiet is the
            // signal that something stopped.
            'complete' => $r->items_complete,
            'total' => $r->items_total,
            'failed' => $r->items_failed,
            'gb' => $r->bytes_total
                ? round(($r->bytes_complete ?? 0) / 1073741824, 2) . ' of '
                    . round($r->bytes_total / 1073741824, 2) . ' GB'
                : null,
            'heard' => $r->progress_at?->diffForHumans(),
            'stale' => $r->progress_at !== null && $r->progress_at->lt(now()->subMinutes(5)),
        ])->all();

        $this->transfers = Transfer::latest('id')->limit(5)->get()->map(function (Transfer $t) {
            $progress = $t->progress();

            return [
                'id' => $t->id,
                'source' => $t->source_url,
                'state' => $t->state,
                'error' => $t->last_error,
                'files' => $t->total_files,
                'gb' => round($t->total_bytes / 1073741824, 1),
                'done' => $progress['complete'] + $progress['skipped'],
                'failed' => $progress['failed'],
                'percent' => $t->total_bytes > 0
                    ? min(100, (int) round($progress['done_bytes'] / $t->total_bytes * 100))
                    : 0,
            ];
        })->all();
    }

    /* ------------------------------------------------------ approving ---- */

    public function approve(int $id): void
    {
        if (! $this->confirmPassword()) {
            return;
        }

        $request = TransferRequest::find($id);

        if ($request === null || ! app(TransferApprovals::class)->approve($request, auth()->user())) {
            Notification::make()->danger()
                ->title('That request can no longer be approved')
                ->body('It may have expired or already been answered.')
                ->send();
        } else {
            Notification::make()->success()
                ->title('Approved')
                ->body('That server can now read what it asked for. You can revoke this at any time.')
                ->send();
        }

        $this->password = '';
        $this->refresh();
    }

    public function deny(int $id): void
    {
        $request = TransferRequest::find($id);

        if ($request !== null) {
            app(TransferApprovals::class)->deny($request);
        }

        $this->refresh();
    }

    public function revoke(int $id): void
    {
        $request = TransferRequest::find($id);

        if ($request !== null) {
            app(TransferApprovals::class)->revoke($request);

            Notification::make()->success()
                ->title('Stopped')
                ->body('That transfer can no longer read anything.')
                ->send();
        }

        $this->refresh();
    }

    /* ------------------------------------------------------ requesting --- */

    public function requestTransfer(): void
    {
        if (! $this->confirmPassword()) {
            return;
        }

        $wants = array_keys(array_filter($this->wants));

        if ($wants === []) {
            Notification::make()->danger()->title('Choose what to bring across')->send();

            return;
        }

        $url = rtrim(trim($this->sourceUrl), '/');

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            Notification::make()->danger()
                ->title('That address does not look right')
                ->body('A tailnet name or a forwarded address, including http:// or https://.')
                ->send();

            return;
        }

        $transfer = Transfer::create([
            'source_url' => $url,
            'wants' => $wants,
            'state' => Transfer::REQUESTED,
        ]);

        if (app(TransferReceiver::class)->request($transfer)) {
            Notification::make()->success()
                ->title('Asked')
                ->body('Now approve it on the other machine. Nothing moves until someone there says yes.')
                ->send();
        } else {
            Notification::make()->danger()
                ->title('Could not ask that server')
                ->body($transfer->fresh()->last_error ?? 'It did not answer.')
                ->send();
        }

        $this->password = '';
        $this->refresh();
    }

    /**
     * Checks for approval and starts if it has been given.
     *
     * Polled from the page rather than pushed, because the source cannot reach
     * back into a machine behind NAT — which is the usual case.
     */
    public function checkAndStart(int $id): void
    {
        $transfer = Transfer::find($id);

        if ($transfer === null) {
            return;
        }

        $state = app(TransferReceiver::class)->poll($transfer);

        if ($state === 'approved') {
            RunTransferJob::dispatch($transfer->id);

            Notification::make()->success()
                ->title('Approved — starting')
                ->body('It runs in the background and picks up where it left off if interrupted.')
                ->send();
        } elseif ($state === 'pending') {
            Notification::make()->title('Still waiting for approval')->send();
        } else {
            Notification::make()->danger()->title('That request is ' . $state)->send();
        }

        $this->refresh();
    }

    public function pause(int $id): void
    {
        Transfer::where('id', $id)->update(['state' => Transfer::PAUSED]);

        $this->refresh();
    }

    public function resume(int $id): void
    {
        // Resuming is the same operation as starting: whatever is not complete
        // is what is left to do.
        Transfer::where('id', $id)->update(['state' => Transfer::RUNNING]);

        RunTransferJob::dispatch($id);

        $this->refresh();
    }

    /**
     * Stops a transfer here and on the machine being copied.
     *
     * Distinct from pausing, which is what the button beside it does: pausing
     * leaves the request approved and the token live over there, so a transfer
     * paused and forgotten leaves another machine able to read this one until
     * the token lapses. Cancelling ends it at both ends.
     */
    public function cancel(int $id): void
    {
        $transfer = Transfer::find($id);

        if ($transfer === null) {
            $this->refresh();

            return;
        }

        if (app(TransferReceiver::class)->cancel($transfer)) {
            Notification::make()->success()
                ->title('Cancelled')
                ->body('Stopped here, and the other server has been told.')
                ->send();
        } else {
            // Cancelled locally either way — the transfer is over here
            // regardless of whether the source could be reached.
            Notification::make()->warning()
                ->title('Cancelled here only')
                ->body($transfer->fresh()->last_error ?? 'That server could not be told.')
                ->send();
        }

        $this->confirming = null;
        $this->refresh();
    }

    /**
     * Removes a finished transfer from the list.
     *
     * The rule about what may be removed lives in `TransferReceiver::discard()`
     * with the deletion itself, so it can be tested without driving the page.
     */
    public function delete(int $id): void
    {
        $transfer = Transfer::find($id);

        if ($transfer === null) {
            $this->refresh();

            return;
        }

        if (app(TransferReceiver::class)->discard($transfer)) {
            Notification::make()->success()->title('Removed from the list')->send();
        } else {
            Notification::make()->danger()
                ->title('That transfer is still running')
                ->body('Cancel it first, then delete it.')
                ->send();
        }

        $this->confirming = null;
        $this->refresh();
    }

    /**
     * A second deliberate act before anything is handed over or fetched.
     *
     * Not the security boundary — the approval on the other machine is — but
     * it stops someone at an unlocked laptop, which is a real thing that
     * happens.
     */
    private function confirmPassword(): bool
    {
        if (Hash::check($this->password, auth()->user()->password)) {
            return true;
        }

        Notification::make()->danger()
            ->title('That password is not right')
            ->send();

        return false;
    }
}
