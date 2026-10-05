{{--
    The modal behind a Background work row.

    Three questions, in the order someone asks them: what is it doing right
    now, what is it about to do, and what has it been saying. The log is last
    because it is the longest, not because it matters least — it is usually
    where the answer is.
--}}
<div class="space-y-6 text-sm">
    @if ($detail['running'] !== [])
        <div>
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">In hand now</p>

            <div class="mt-2 space-y-2">
                @foreach ($detail['running'] as $row)
                    <div class="flex items-start justify-between gap-4 rounded-lg bg-success-50 px-3 py-2 dark:bg-success-400/10">
                        <div class="min-w-0">
                            <p class="truncate font-medium text-gray-900 dark:text-white">{{ $row['target'] }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                job #{{ $row['id'] }}
                                @if ($row['since'])
                                    · started {{ $row['since']->diffForHumans() }}
                                @endif
                                @if ($row['attempts'] > 1)
                                    · attempt {{ $row['attempts'] }}
                                @endif
                            </p>
                        </div>

                        <x-filament::badge color="success">Running</x-filament::badge>
                    </div>
                @endforeach
            </div>
        </div>
    @else
        <p class="text-gray-500 dark:text-gray-400">
            {{-- Said carefully: nothing reserved cannot tell the two apart. --}}
            Nothing of this kind is reserved right now — the worker may be between jobs, or stopped.
        </p>
    @endif

    @if ($detail['upcoming'] !== [])
        <div>
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Next up</p>

            <table class="mt-2 w-full">
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($detail['upcoming'] as $row)
                        <tr>
                            <td class="truncate py-1.5 pr-4 text-gray-700 dark:text-gray-200">{{ $row['target'] }}</td>
                            <td class="w-32 py-1.5 text-right text-xs text-gray-500 dark:text-gray-400">
                                {{ $row['waiting_since']?->diffForHumans() ?? '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($detail['failures']->isNotEmpty())
        <div>
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Failures</p>

            <table class="mt-2 w-full">
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($detail['failures'] as $failure)
                        <tr>
                            <td class="py-1.5 pr-4 text-gray-700 dark:text-gray-200">{{ $failure['reason'] }}</td>
                            <td class="w-16 py-1.5 pr-4 text-right tabular-nums text-danger-600 dark:text-danger-400">{{ $failure['count'] }}</td>
                            <td class="w-32 py-1.5 text-right text-xs text-gray-500 dark:text-gray-400">
                                {{ $failure['last']?->diffForHumans() ?? '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div>
        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Recent log</p>

        @if (! $detail['has_log'])
            <p class="mt-2 text-gray-500 dark:text-gray-400">Nothing has been written to the log yet.</p>
        @elseif ($detail['log']->isEmpty())
            <p class="mt-2 text-gray-500 dark:text-gray-400">
                The log has nothing about these items.
            </p>
        @else
            <div class="mt-2 max-h-80 space-y-1 overflow-y-auto rounded-lg bg-gray-50 p-3 font-mono text-xs dark:bg-white/5">
                @foreach ($detail['log'] as $entry)
                    <div class="flex gap-2">
                        <span class="shrink-0 text-gray-400">{{ $entry['at']?->format('H:i:s') ?? '--:--:--' }}</span>

                        <span @class([
                            'shrink-0 w-14 uppercase',
                            'text-danger-600 dark:text-danger-400' => in_array($entry['level'], ['error', 'critical', 'alert', 'emergency'], true),
                            'text-warning-600 dark:text-warning-400' => $entry['level'] === 'warning',
                            'text-gray-400' => ! in_array($entry['level'], ['error', 'critical', 'alert', 'emergency', 'warning'], true),
                        ])>{{ $entry['level'] }}</span>

                        <span class="min-w-0 text-gray-700 dark:text-gray-200">
                            {{ $entry['message'] }}

                            @if (($entry['context']['hint'] ?? null))
                                {{-- The hint is the part that tells someone what to
                                     do, so it is worth more room than the rest. --}}
                                <span class="block text-gray-500 dark:text-gray-400">{{ $entry['context']['hint'] }}</span>
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
