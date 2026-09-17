<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Resources\Books\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BookForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Section::make('Book')
                    ->description('An ISBN gives an exact match; a title alone usually works too.')
                    ->columnSpan(['default' => 1, 'lg' => 2])
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextInput::make('bookMetadata.isbn_13')
                            ->label('ISBN')
                            ->placeholder('Scan or type an ISBN-13')
                            ->maxLength(20)
                            // Barcode scanners type the digits then press Enter,
                            // so this behaves like a scan field with no extra work.
                            ->autofocus()
                            ->helperText('Most reliable lookup — barcode scanners work here.')
                            ->columnSpanFull(),

                        TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        TextInput::make('bookMetadata.author')
                            ->label('Author')
                            ->maxLength(255),

                        TextInput::make('bookMetadata.publisher')
                            ->label('Publisher')
                            ->maxLength(255),

                        TextInput::make('bookMetadata.publish_year')
                            ->label('Year')
                            ->numeric()
                            ->minValue(1000)
                            ->maxValue(2999),

                        TextInput::make('bookMetadata.pages')
                            ->label('Pages')
                            ->numeric()
                            ->minValue(1),

                        Textarea::make('notes')
                            ->label('Notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),

                Section::make('File')
                    ->description('Upload the book to read it in the browser.')
                    ->columnSpan(['default' => 1, 'lg' => 1])
                    ->schema([
                        FileUpload::make('file_path')
                            ->label('')
                            ->disk(config('filesystems.default'))
                            ->directory(config('library.inbox', 'media/unsorted'))
                            ->acceptedFileTypes([
                                'application/epub+zip',
                                'application/pdf',
                                'application/x-mobipocket-ebook',
                                'application/vnd.amazon.ebook',
                                'application/vnd.comicbook+zip',
                                'application/vnd.comicbook-rar',
                                'application/zip',
                                'application/x-cbr',
                            ])
                            ->maxSize(512000) // 500 MB — scanned PDFs get large
                            ->preserveFilenames()
                            ->helperText('EPUB, PDF, and CBZ/CBR read in-app. MOBI and AZW3 download only.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Library')
                    ->columnSpan(['default' => 1, 'lg' => 1])
                    ->schema([
                        Select::make('user_rating')
                            ->label('Your rating')
                            ->options(array_combine(range(1, 10), range(1, 10)))
                            ->native(false)
                            ->placeholder('Not rated'),

                        Toggle::make('owned')->label('Owned')->default(true),
                        Toggle::make('wishlist')->label('Wishlist'),
                    ]),

                Section::make('Series & identifiers')
                    ->columnSpanFull()
                    ->columns(['default' => 1, 'md' => 4])
                    ->collapsed()
                    ->schema([
                        TextInput::make('bookMetadata.series_name')
                            ->label('Series')
                            ->maxLength(255),

                        TextInput::make('bookMetadata.series_position')
                            ->label('Book #')
                            ->numeric()
                            ->minValue(1),

                        TextInput::make('bookMetadata.isbn_10')
                            ->label('ISBN-10')
                            ->maxLength(16),

                        TextInput::make('bookMetadata.open_library_id')
                            ->label('Open Library ID')
                            ->maxLength(32),
                    ]),
            ]);
    }
}
