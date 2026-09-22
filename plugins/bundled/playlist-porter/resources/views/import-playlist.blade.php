{{-- SPDX-License-Identifier: AGPL-3.0-or-later --}}
{{-- Import Playlist (S-311): a custom plugin page — a file picker, then the
     result of the port with unmatched tracks to resolve. --}}
<x-filament-panels::page>
    @php($import = $this->currentImport())

    {{-- Upload --}}
    <section class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <h2 class="text-base font-semibold text-gray-950 dark:text-white">Import a playlist file</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Upload an <strong>M3U</strong>, <strong>CSV</strong> (including Spotify/Exportify exports), or
            <strong>XSPF</strong> file. Each track is matched to your library and saved as a playlist;
            anything that can’t be matched is listed below for you to resolve.
        </p>

        <form wire:submit="import" class="mt-4 space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Playlist file</label>
                <input type="file" wire:model="file" accept=".m3u,.m3u8,.csv,.xspf,text/plain,text/csv,application/xml"
                    class="mt-1 block w-full text-sm text-gray-700 file:mr-4 file:rounded-lg file:border-0 file:bg-primary-600 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-primary-500 dark:text-gray-300" />
                @error('file') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                <div wire:loading wire:target="file" class="mt-1 text-sm text-gray-500">Uploading…</div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Playlist name <span class="text-gray-400">(optional)</span></label>
                <input type="text" wire:model="name" placeholder="Taken from the file if left blank"
                    class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white" />
            </div>

            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="import">
                <span wire:loading.remove wire:target="import">Import</span>
                <span wire:loading wire:target="import">Matching…</span>
            </x-filament::button>
        </form>
    </section>

    {{-- Result --}}
    @if ($import)
        <section class="fi-section mt-6 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ $import->name }}</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Matched <strong>{{ $import->matched_tracks }}</strong> of {{ $import->total_tracks }} tracks
                        · from {{ strtoupper($import->source_format) }}
                    </p>
                </div>
                @if ($import->collection_id)
                    <a href="{{ url('/admin/collections/'.$import->collection_id.'/edit') }}"
                        class="text-sm font-semibold text-primary-600 hover:text-primary-500">Open playlist →</a>
                @endif
            </div>

            @php($unmatched = $import->unmatched ?? [])
            @if (count($unmatched) > 0)
                <h3 class="mt-6 text-sm font-semibold text-gray-950 dark:text-white">
                    {{ count($unmatched) }} track{{ count($unmatched) === 1 ? '' : 's' }} not in your library
                </h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Match one to a track you own, or leave it out.
                </p>

                <ul class="mt-3 divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($unmatched as $index => $track)
                        <li class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between" wire:key="unmatched-{{ $index }}">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-gray-950 dark:text-white">
                                    {{ $track['title'] ?? $track['source_label'] ?? 'Unknown track' }}
                                </p>
                                @if (!empty($track['artist']) || !empty($track['album']))
                                    <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                                        {{ collect([$track['artist'] ?? null, $track['album'] ?? null])->filter()->implode(' · ') }}
                                    </p>
                                @endif
                            </div>
                            <div class="flex items-center gap-2">
                                <input type="text" wire:model="resolveTo.{{ $index }}"
                                    placeholder="Library track id"
                                    class="w-40 rounded-lg border-gray-300 text-xs shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white" />
                                <x-filament::button size="sm" color="gray" wire:click="resolve({{ $index }})">
                                    Match
                                </x-filament::button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-6 text-sm text-success-600">Every track was matched to your library.</p>
            @endif
        </section>
    @endif
</x-filament-panels::page>
