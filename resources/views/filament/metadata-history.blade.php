@php
    /**
     * Each version stores the state *before* its change, so the diff shown on
     * a row is that snapshot against the one after it — which is the change
     * that version records.
     */
    $ordered = $versions->values();
@endphp

<div class="space-y-3">
    @forelse ($ordered as $index => $version)
        @php
            // The newer neighbour is the previous row, since these are newest
            // first. For the newest version, the comparison is against the
            // item as it stands now.
            $after = $index === 0
                ? $history->snapshot($record)
                : $ordered[$index - 1]->snapshot;

            $changes = $history->diff($version->snapshot, $after);
        @endphp

        <div class="rounded-lg border border-gray-200 dark:border-white/10">
            <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">
                        {{ $version->reasonLabel() }}
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ $version->created_at->diffForHumans() }}
                        &middot; {{ $version->created_at->format('j M Y, H:i') }}
                        @if ($version->user)
                            &middot; {{ $version->user->name }}
                        @endif
                    </p>
                </div>

                <x-filament::button
                    size="xs"
                    color="gray"
                    wire:click="mountAction('restoreVersion', { version: {{ $version->id }} }, { table: true, record: '{{ $record->id }}' })"
                >
                    Restore
                </x-filament::button>
            </div>

            @if (count($changes) > 0)
                <div class="border-t border-gray-100 px-4 py-3 dark:border-white/5">
                    <table class="w-full text-xs">
                        <tbody>
                            @foreach ($changes as $change)
                                <tr class="align-top">
                                    <td class="w-1/4 py-1 pr-3 font-medium text-gray-700 dark:text-gray-300">
                                        {{ $change['label'] }}
                                    </td>
                                    <td class="w-3/8 py-1 pr-3">
                                        <span class="text-gray-400 line-through dark:text-gray-500">
                                            {{ \Illuminate\Support\Str::limit((string) ($change['old'] ?? '—'), 80) ?: '—' }}
                                        </span>
                                    </td>
                                    <td class="w-3/8 py-1 text-gray-900 dark:text-white">
                                        {{ \Illuminate\Support\Str::limit((string) ($change['new'] ?? '—'), 80) ?: '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="border-t border-gray-100 px-4 py-2 text-xs text-gray-500 dark:border-white/5 dark:text-gray-400">
                    Nothing changed after this point.
                </p>
            @endif
        </div>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">
            No history yet. A version is recorded the first time enrichment changes something.
        </p>
    @endforelse
</div>
