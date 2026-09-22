{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Recent plugin-related log lines, for diagnosing a plugin that won't load. --}}
<div class="space-y-3">
    @if (empty($lines))
        <p class="text-sm text-gray-500 dark:text-gray-400">
            No plugin-related log lines yet. If a plugin failed to load, try enabling it again to
            reproduce the error, then reopen this.
        </p>
    @else
        <p class="text-xs text-gray-500 dark:text-gray-400">
            The most recent plugin log lines (newest at the bottom).
        </p>
        <div class="max-h-96 overflow-auto rounded-lg bg-gray-950 p-4 dark:bg-black">
            <pre class="whitespace-pre-wrap break-words font-mono text-xs leading-relaxed text-gray-200">{{ implode("\n", $lines) }}</pre>
        </div>
    @endif
</div>
