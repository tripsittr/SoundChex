<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Widgets;

use App\Enums\ProcessingStatus;
use App\Models\MediaItem;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * The most recently added items, so an admin can see at a glance whether an
 * import landed and whether its metadata came out sensibly.
 */
class RecentActivity extends TableWidget
{

    /**
     * Widgets are renderable independently of the page that hosts them, so
     * this repeats the dashboard's gate rather than relying on it. Without it
     * a capped profile saw library counts, storage totals and titles above
     * its rating.
     */
    public static function canView(): bool
    {
        return \App\Filament\Pages\Dashboard::canAccess();
    }
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recently added')
            ->query(
                MediaItem::query()
                    ->with(['musicMetadata', 'movieMetadata', 'showMetadata', 'bookMetadata'])
                    ->withCount('plays')
                    ->latest()
                    ->limit(8),
            )
            // The widget shows a fixed recent slice; paginating it would
            // suggest it's a full browse view, which the resources already are.
            ->paginated(false)
            ->columns([
                ImageColumn::make('cover_image_url')
                    ->label('')
                    ->square()
                    ->defaultImageUrl(fn (): string => 'https://placehold.co/72x72/1f2937/6b7280?text=%E2%99%AB'),

                TextColumn::make('title')
                    ->limit(40)
                    ->weight('medium')
                    ->description(fn (MediaItem $record): ?string => $record->subtitle()),

                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state->label()),

                TextColumn::make('plays_count')
                    ->label('Plays')
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),

                TextColumn::make('processing_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (ProcessingStatus $state): string => $state->label())
                    ->color(fn (ProcessingStatus $state): string => $state->color()),

                TextColumn::make('created_at')
                    ->label('Added')
                    ->since(),
            ])
            ->emptyStateHeading('Nothing added yet')
            ->emptyStateDescription('Import a folder with `php artisan library:import-music`.');
    }
}
