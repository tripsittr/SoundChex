<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\PlaylistPorter\Filament;

use App\Filament\Concerns\RestrictsToServerAdmins;
use App\Models\MediaItem;
use App\Models\PlaylistImport;
use App\Services\Import\PlaylistImportService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportFileUploads\WithFileUploads;
use UnitEnum;

/**
 * Import a playlist from a file (S-311).
 *
 * A deliberately plain, custom page — not a generated Filament form — rendered
 * from this plugin's own Blade view: a file picker, and afterwards the result of
 * the port, with the tracks that could not be matched listed for the admin to
 * resolve by picking a library item. It drives the shared porting engine
 * (PlaylistImportService, S-310), the same one the mobile app uses.
 */
class ImportPlaylist extends Page
{
    use RestrictsToServerAdmins;
    use WithFileUploads;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownOnSquareStack;

    protected static string|UnitEnum|null $navigationGroup = 'Library';

    protected static ?string $title = 'Import Playlist';

    protected static ?string $navigationLabel = 'Import Playlist';

    protected string $view = 'playlist-porter::import-playlist';

    /** The uploaded playlist file (Livewire temporary upload). */
    public $file;

    /** An optional name for the resulting playlist. */
    public string $name = '';

    /** The finished import, once one has run — drives the results panel. */
    public ?int $importId = null;

    /** For each unmatched row, the library item id the admin picked to resolve it. */
    public array $resolveTo = [];

    /**
     * Parse and match the uploaded file, creating the playlist.
     */
    public function import(PlaylistImportService $service): void
    {
        $this->validate([
            'file' => ['required', 'file', 'max:5120'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $contents = (string) file_get_contents($this->file->getRealPath());
        $extension = strtolower($this->file->getClientOriginalExtension());

        try {
            $parsed = $service->parseFile($contents, $extension);
        } catch (\RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        if ($parsed['tracks'] === []) {
            Notification::make()->title('No tracks found in that file.')->danger()->send();

            return;
        }

        $name = $this->name !== ''
            ? $this->name
            : ($parsed['name'] ?? pathinfo($this->file->getClientOriginalName(), PATHINFO_FILENAME));

        $import = PlaylistImport::create([
            'user_id' => Auth::id(),
            'source' => 'file',
            'source_format' => $parsed['format'],
            'name' => $name,
            'status' => PlaylistImport::STATUS_PENDING,
            'total_tracks' => count($parsed['tracks']),
        ]);

        // The admin is present and watching, so run it now rather than queue —
        // even a long playlist is a few seconds of matching.
        $service->run($import, $parsed['tracks'], $name);

        $this->importId = $import->id;
        $this->file = null;
        $this->name = '';
        $this->resolveTo = [];

        $fresh = $import->fresh();
        Notification::make()
            ->title("Imported \"{$fresh->name}\"")
            ->body("{$fresh->matched_tracks} of {$fresh->total_tracks} tracks matched your library.")
            ->success()
            ->send();
    }

    /**
     * Resolve one unmatched track by attaching the chosen library item to the
     * playlist and dropping it from the unmatched list.
     */
    public function resolve(int $index): void
    {
        $import = $this->currentImport();

        if ($import === null || $import->collection === null) {
            return;
        }

        $itemId = (int) ($this->resolveTo[$index] ?? 0);
        $item = MediaItem::find($itemId);

        if ($item === null) {
            Notification::make()->title('Pick a track to match it to first.')->warning()->send();

            return;
        }

        $unmatched = $import->unmatched ?? [];

        if (! array_key_exists($index, $unmatched)) {
            return;
        }

        $next = (int) $import->collection->mediaItems()->max('sort_order') + 1;
        $import->collection->mediaItems()->syncWithoutDetaching([$item->id => ['sort_order' => $next]]);

        unset($unmatched[$index], $this->resolveTo[$index]);
        $import->update([
            'unmatched' => array_values($unmatched),
            'matched_tracks' => $import->matched_tracks + 1,
        ]);

        Notification::make()->title('Matched — added to the playlist.')->success()->send();
    }

    /**
     * Library tracks matching a search string, for the resolve picker. Keyed by
     * id → "Title — Artist", so the Blade can offer them in a select.
     *
     * @return array<int, string>
     */
    public function candidates(string $search): array
    {
        $search = trim($search);

        if (mb_strlen($search) < 2) {
            return [];
        }

        return MediaItem::query()
            ->where('type', \App\Enums\MediaItemType::Music)
            ->where('title', 'like', "%{$search}%")
            ->with('musicMetadata')
            ->limit(20)
            ->get()
            ->mapWithKeys(fn (MediaItem $i): array => [
                $i->id => $i->title.($i->musicMetadata?->artist ? ' — '.$i->musicMetadata->artist : ''),
            ])
            ->all();
    }

    public function currentImport(): ?PlaylistImport
    {
        if ($this->importId === null) {
            return null;
        }

        $import = PlaylistImport::find($this->importId);

        return $import !== null && $import->user_id === Auth::id() ? $import : null;
    }
}
