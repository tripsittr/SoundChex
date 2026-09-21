<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace SoundChex\ActivityLog\Filament;

use App\Filament\Concerns\RestrictsToServerAdmins;
use App\Models\AuditLogEntry;
use App\Models\Profile;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The unified activity/audit timeline (S-284), contributed by the Activity Log
 * plugin through the admin-page seam.
 *
 * A read-only, filterable stream of everything that has happened — filter by
 * event type, by the profile that acted, or search a subject. It gates itself to
 * server admins, the same door the built-in System pages use, because a full
 * history of who did what is exactly the kind of thing a capped profile should
 * not read.
 *
 * The page lives in the plugin's namespace; Filament knows about it only because
 * the plugin called `Registry::adminPage(self::class)` — Filament auto-discovers
 * pages under `app/Filament`, not a plugin's own directory.
 */
class AuditLog extends Page implements HasTable
{
    use InteractsWithTable;
    use RestrictsToServerAdmins;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $title = 'Audit Log';

    protected static ?string $navigationLabel = 'Audit Log';

    protected string $view = 'activity-log::audit-log';

    public function table(Table $table): Table
    {
        return $table
            ->query(AuditLogEntry::query())
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('event')
                    ->label('Event')
                    ->badge()
                    ->sortable()
                    ->searchable(),

                TextColumn::make('summary')
                    ->label('What happened')
                    ->wrap()
                    ->searchable(),

                TextColumn::make('actor_name')
                    ->label('Who')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('subject_title')
                    ->label('Subject')
                    ->placeholder('—')
                    ->wrap()
                    ->searchable(),
            ])
            ->filters([
                // The events actually present in the log, so the filter never
                // lists an event that has not happened.
                SelectFilter::make('event')
                    ->label('Event type')
                    ->options(fn (): array => AuditLogEntry::query()
                        ->distinct()
                        ->orderBy('event')
                        ->pluck('event', 'event')
                        ->all()),

                // The profiles that appear as an actor in the log.
                SelectFilter::make('profile_id')
                    ->label('Acted by')
                    ->options(fn (): array => Profile::query()
                        ->whereIn('id', AuditLogEntry::query()->whereNotNull('profile_id')->distinct()->pluck('profile_id'))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),
            ])
            ->recordActions([])
            ->emptyStateHeading('Nothing recorded yet')
            ->emptyStateDescription('Activity appears here as it happens across the server.')
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentList);
    }
}
