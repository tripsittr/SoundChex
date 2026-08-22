<?php

namespace App\Filament\Resources\Music\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MusicForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns([
                'default' => 1,
                'lg' => 3,
            ])
            ->components([
                Section::make('Audio File')
                    ->description('Upload a track and its tags are read automatically.')
                    ->columnSpan(['default' => 1, 'lg' => 1])
                    ->schema([
                        FileUpload::make('file_path')
                            ->label('Audio file')
                            ->disk(config('filesystems.default'))
                            ->directory(config('library.inbox', 'media/unsorted'))
                            ->acceptedFileTypes([
                                'audio/mpeg', 'audio/mp4', 'audio/flac', 'audio/x-flac',
                                'audio/wav', 'audio/x-wav', 'audio/ogg', 'audio/aac',
                                'audio/x-m4a', 'audio/aiff', 'audio/x-aiff',
                            ])
                            ->maxSize(102400) // 100 MB
                            ->helperText('MP3, FLAC, WAV, M4A, OGG, AIFF — up to 100 MB.')
                            ->preserveFilenames()
                            ->columnSpanFull(),

                        FileUpload::make('cover_image_url')
                            ->label('Cover art')
                            ->disk('public')
                            ->directory('media/covers')
                            ->image()
                            ->imageEditor()
                            ->maxSize(4096)
                            ->helperText('Left empty, embedded art is used.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Track')
                    ->columnSpan(['default' => 1, 'lg' => 2])
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        TextInput::make('musicMetadata.artist')
                            ->label('Artist')
                            ->maxLength(255),

                        TextInput::make('musicMetadata.album')
                            ->label('Album')
                            ->maxLength(255),

                        TextInput::make('musicMetadata.track_number')
                            ->label('Track #')
                            ->numeric()
                            ->minValue(1),

                        TextInput::make('musicMetadata.disc_number')
                            ->label('Disc #')
                            ->numeric()
                            ->minValue(1),

                        TextInput::make('musicMetadata.release_year')
                            ->label('Year')
                            ->numeric()
                            ->minValue(1000)
                            ->maxValue(2999),

                        TextInput::make('musicMetadata.label')
                            ->label('Label')
                            ->maxLength(255),
                    ]),

                Section::make('Musical Detail')
                    ->columnSpan(['default' => 1, 'lg' => 2])
                    ->columns(3)
                    ->collapsed()
                    ->schema([
                        TextInput::make('musicMetadata.bpm')
                            ->label('BPM')
                            ->numeric()
                            ->step(0.1),

                        Select::make('musicMetadata.key')
                            ->label('Key')
                            ->options(array_combine(
                                ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'],
                                ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'],
                            ))
                            ->native(false),

                        Select::make('musicMetadata.scale')
                            ->label('Scale')
                            ->options(['major' => 'Major', 'minor' => 'Minor'])
                            ->native(false),

                        TextInput::make('musicMetadata.isrc')
                            ->label('ISRC')
                            ->maxLength(32),

                        TextInput::make('musicMetadata.musicbrainz_recording_id')
                            ->label('MusicBrainz Recording ID')
                            ->maxLength(64)
                            ->columnSpan(2),
                    ]),

                Section::make('Library')
                    ->columnSpan(['default' => 1, 'lg' => 1])
                    ->schema([
                        Select::make('user_rating')
                            ->label('Your rating')
                            ->options(array_combine(range(1, 10), range(1, 10)))
                            ->native(false)
                            ->placeholder('Not rated'),

                        Toggle::make('owned')
                            ->label('Owned')
                            ->default(true),

                        Toggle::make('wishlist')
                            ->label('Wishlist'),

                        Textarea::make('notes')
                            ->label('Notes')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
