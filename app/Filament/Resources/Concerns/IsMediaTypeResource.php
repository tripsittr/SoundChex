<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Concerns;

use App\Enums\MediaItemType;
use App\Models\MediaItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared plumbing for the per-type media resources.
 *
 * All four types are rows in `media_items`, so every resource needs the same
 * type constraint, the same eager loading, and the same navigation badge.
 * Only the type and its metadata relation differ.
 */
trait IsMediaTypeResource
{
    /** The type this resource is scoped to. */
    abstract public static function mediaType(): MediaItemType;

    /**
     * The type-specific metadata relation to eager load.
     */
    protected static function metadataRelation(): string
    {
        return match (static::mediaType()) {
            MediaItemType::Music => 'musicMetadata',
            MediaItemType::Movie => 'movieMetadata',
            MediaItemType::Show  => 'showMetadata',
            MediaItemType::Book  => 'bookMetadata',
        };
    }

    /**
     * MediaItem backs every media type, so every query through this resource
     * is constrained to one. Applies to lists, edits, and global search.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', static::mediaType())
            ->with(static::metadataRelation());
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['title'];
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails($record): array
    {
        return array_filter([
            'Detail' => $record->subtitle(),
            'Year'   => $record->year(),
        ]);
    }

    /**
     * Applies the type, owner, and pending status to a newly created record.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function prepareCreateData(array $data, ?int $userId): array
    {
        $data['type'] = static::mediaType();
        $data['user_id'] = $userId;
        $data['processing_status'] = \App\Enums\ProcessingStatus::Pending;

        return $data;
    }

    /**
     * Creates the item plus its (possibly empty) metadata row.
     *
     * The metadata row must exist before enrichment runs — every source writes
     * into it rather than creating it.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $metadata
     */
    public static function createWithMetadata(array $data, array $metadata): MediaItem
    {
        /** @var MediaItem $record */
        $record = static::getModel()::create($data);

        $record->{static::metadataRelation()}()->create(
            array_filter($metadata, fn ($value) => filled($value)),
        );

        return $record;
    }
}
