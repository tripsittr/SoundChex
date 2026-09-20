<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Duplicates;

use App\Enums\DuplicateStatus;
use App\Filament\Concerns\RestrictsToAdmins;
use App\Filament\Resources\Duplicates\Pages\ListDuplicates;
use App\Models\MediaItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Review screen for files detected as byte-identical copies.
 *
 * Read-only by design: the only writes are the two decisions (merge or keep),
 * both of which are table actions. There's no create or edit form because a
 * duplicate isn't something you author — it's something the scanner found.
 */
class DuplicateResource extends Resource
{
    use RestrictsToAdmins;

    protected static ?string $model = MediaItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Media Library';

    // "Needs Review" rather than "Duplicates": the screen already reviews more
    // than duplicates (cover art), and is where future review types land too.
    // The tabs pick the review type; this is the queue as a whole.
    protected static ?string $navigationLabel = 'Needs Review';

    protected static ?string $modelLabel = 'item to review';

    protected static ?string $pluralModelLabel = 'items to review';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'title';

    /**
     * Only flagged rows, newest first.
     *
     * Resolved rows stay visible so a merge can be seen after the fact, but
     * anything never flagged is not a duplicate and doesn't belong here.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereNotNull('duplicate_status')
            ->with('duplicateOf');
    }

    public static function table(Table $table): Table
    {
        return DuplicatesTable::configure($table);
    }

    /**
     * The badge is the total review work across every type — pending duplicates
     * plus covers awaiting a look — so the sidebar shows everything waiting, not
     * just one tab's share.
     */
    public static function getNavigationBadge(): ?string
    {
        $model = static::getModel();

        $waiting = $model::query()
            ->where('duplicate_status', DuplicateStatus::Pending)
            ->count()
            + $model::query()
                ->where('needs_cover_review', true)
                ->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDuplicates::route('/'),
        ];
    }
}
