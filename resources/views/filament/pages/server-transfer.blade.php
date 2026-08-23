<x-filament-panels::page>
    {{-- Approving. This is the security boundary: nothing can be read from
         this machine until someone here says so, and what they say yes to
         stays revocable while it runs. --}}
    @if ($pending !== [])
        <x-filament::section>
            <x-slot name="heading">
                {{ count($pending) }} {{ Str::plural('server', count($pending)) }} asking to copy this one
            </x-slot>

            <x-slot name="description">
                Check the code matches the one on the other machine before approving.
                It is how you know this is the request you just started rather than
                someone else's arriving at the same moment.
            </x-slot>

            <div class="space-y-3">
                @foreach ($pending as $request)
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-medium">
                                    {{ $request['device'] }}
                                    <span class="font-mono text-sm opacity-60">{{ $request['ip'] }}</span>
                                </p>
                                <p class="mt-1 text-sm opacity-70">
                                    {{ $request['platform'] }} · asked {{ $request['when'] }}
                                </p>
                                <p class="mt-1 text-sm">Wants {{ $request['wants'] }}</p>
                            </div>

                            <div class="text-right">
                                <p class="text-xs uppercase tracking-wide opacity-60">Code</p>
                                <p class="font-mono text-2xl font-bold tracking-widest">{{ $request['code'] }}</p>
                            </div>
                        </div>

                        <div class="mt-4 flex gap-2">
                            <x-filament::button wire:click="approve({{ $request['id'] }})" color="danger" size="sm">
                                Approve
                            </x-filament::button>

                            <x-filament::button wire:click="deny({{ $request['id'] }})" color="gray" size="sm">
                                Deny
                            </x-filament::button>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="mt-4">
                <label class="block text-sm font-medium">Your password, to approve</label>
                <input type="password" wire:model="password"
                       class="mt-1 w-full max-w-xs rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"
                       autocomplete="current-password">
            </div>
        </x-filament::section>
    @endif

    {{-- Approved and still readable. Shown so it can be stopped: 46 GB takes
         hours, which is long enough to change your mind. --}}
    @if ($active !== [])
        <x-filament::section>
            <x-slot name="heading">Currently allowed</x-slot>

            <div class="space-y-2">
                @foreach ($active as $request)
                    <div class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 p-3 dark:border-white/10">
                        <div>
                            <p class="font-medium">{{ $request['device'] }}
                                <span class="font-mono text-sm opacity-60">{{ $request['ip'] }}</span>
                            </p>
                            <p class="text-sm opacity-70">
                                Reading {{ $request['wants'] }} · approved {{ $request['since'] }}
                            </p>
                        </div>

                        <x-filament::button wire:click="revoke({{ $request['id'] }})" color="danger" size="sm">
                            Stop
                        </x-filament::button>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    {{-- Copying another server to this one. --}}
    <x-filament::section>
        <x-slot name="heading">Copy another server to this one</x-slot>

        <x-slot name="description">
            A tailnet name or a forwarded address. Nothing moves until someone
            on that machine approves the request.
        </x-slot>

        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium">Its address</label>
                <input type="url" wire:model="sourceUrl"
                       placeholder="https://macbookair.tail7e590c.ts.net"
                       class="mt-1 w-full rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
            </div>

            <div>
                <p class="text-sm font-medium">What to bring across</p>

                <div class="mt-2 space-y-2">
                    @foreach ([
                        'metadata' => ['The catalogue', 'Titles, artists, albums, artwork. Minutes.'],
                        'files' => ['The media files', 'The actual music, films and books. Hours.'],
                        'profiles' => ['Profiles and history', 'People, resume points, watchlists. The part rescanning cannot rebuild.'],
                        'settings' => ['Settings and keys', 'API keys and watch folders. Often you want this machine to keep its own.'],
                    ] as $key => [$label, $note])
                        <label class="flex items-start gap-2">
                            <input type="checkbox" wire:model="wants.{{ $key }}"
                                   class="mt-1 rounded border-gray-300 dark:border-white/10">
                            <span>
                                <span class="text-sm font-medium">{{ $label }}</span>
                                <span class="block text-xs opacity-60">{{ $note }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium">Your password</label>
                <input type="password" wire:model="password"
                       class="mt-1 w-full max-w-xs rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5"
                       autocomplete="current-password">
            </div>

            <x-filament::button wire:click="requestTransfer">Ask that server</x-filament::button>
        </div>
    </x-filament::section>

    {{-- What is running or has run. --}}
    @if ($transfers !== [])
        <x-filament::section>
            <x-slot name="heading">Transfers</x-slot>

            <div class="space-y-3">
                @foreach ($transfers as $transfer)
                    <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate font-mono text-sm">{{ $transfer['source'] }}</p>
                                <p class="mt-1 text-sm opacity-70">
                                    {{ $transfer['state'] }}
                                    @if ($transfer['files'])
                                        · {{ $transfer['done'] }} of {{ $transfer['files'] }} files
                                        · {{ $transfer['gb'] }} GB
                                    @endif
                                    @if ($transfer['failed'])
                                        · <span class="text-danger-600">{{ $transfer['failed'] }} failed</span>
                                    @endif
                                </p>
                                @if ($transfer['error'])
                                    <p class="mt-1 text-sm text-danger-600">{{ $transfer['error'] }}</p>
                                @endif
                            </div>

                            <div class="flex gap-2">
                                @if ($transfer['state'] === 'requested')
                                    <x-filament::button wire:click="checkAndStart({{ $transfer['id'] }})" size="sm">
                                        Check for approval
                                    </x-filament::button>
                                @elseif ($transfer['state'] === 'running')
                                    <x-filament::button wire:click="pause({{ $transfer['id'] }})" color="gray" size="sm">
                                        Pause
                                    </x-filament::button>
                                @elseif (in_array($transfer['state'], ['paused', 'failed'], true))
                                    <x-filament::button wire:click="resume({{ $transfer['id'] }})" size="sm">
                                        Resume
                                    </x-filament::button>
                                @endif

                                {{-- Both confirm with a second click rather than wire:confirm.
                                     That directive is used nowhere else here, and both buttons
                                     did nothing when clicked while the methods behind them
                                     worked. This is the same round trip as every button above
                                     that does work. --}}

                                {{-- Cancelling ends the request on the other machine too, which
                                     pausing deliberately does not. --}}
                                @if (in_array($transfer['state'], ['requested', 'approved', 'running', 'paused'], true))
                                    @if ($confirming === 'cancel:' . $transfer['id'])
                                        <x-filament::button wire:click="cancel({{ $transfer['id'] }})" color="danger" size="sm">
                                            Stop it on both machines
                                        </x-filament::button>
                                        <x-filament::button wire:click="dismissConfirmation" color="gray" size="sm">
                                            Keep it
                                        </x-filament::button>
                                    @else
                                        <x-filament::button wire:click="askToConfirm('cancel:{{ $transfer['id'] }}')" color="danger" size="sm">
                                            Cancel
                                        </x-filament::button>
                                    @endif
                                @endif

                                {{-- Refused while running, so clearing the list cannot be a way
                                     to abandon a transfer half way. --}}
                                @if ($transfer['state'] !== 'running')
                                    @if ($confirming === 'delete:' . $transfer['id'])
                                        <x-filament::button wire:click="delete({{ $transfer['id'] }})" color="danger" size="sm">
                                            Remove it
                                        </x-filament::button>
                                        <x-filament::button wire:click="dismissConfirmation" color="gray" size="sm">
                                            Keep it
                                        </x-filament::button>
                                    @else
                                        <x-filament::button wire:click="askToConfirm('delete:{{ $transfer['id'] }}')" color="gray" size="sm">
                                            Delete
                                        </x-filament::button>
                                    @endif
                                @endif
                            </div>
                        </div>

                        @if ($transfer['files'])
                            <div class="mt-3 h-2 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                                <div class="h-full bg-primary-500" style="width: {{ $transfer['percent'] }}%"></div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
