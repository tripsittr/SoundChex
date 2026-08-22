<?php

namespace App\Filament\Resources\Books;

use App\Filament\Concerns\RestrictsToAdmins;

use App\Enums\MediaItemType;
use App\Filament\Resources\Books\Pages\CreateBook;
use App\Filament\Resources\Books\Pages\EditBook;
use App\Filament\Resources\Books\Pages\ListBooks;
use App\Filament\Resources\Books\Schemas\BookForm;
use App\Filament\Resources\Books\Tables\BooksTable;
use App\Filament\Resources\Concerns\IsMediaTypeResource;
use App\Models\MediaItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class BookResource extends Resource
{
    use RestrictsToAdmins;

    use IsMediaTypeResource;

    protected static ?string $model = MediaItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Media Library';

    protected static ?string $navigationLabel = 'Books';

    protected static ?string $modelLabel = 'book';

    protected static ?string $pluralModelLabel = 'books';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'title';

    public static function mediaType(): MediaItemType
    {
        return MediaItemType::Book;
    }

    public static function form(Schema $schema): Schema
    {
        return BookForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BooksTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListBooks::route('/'),
            'create' => CreateBook::route('/create'),
            'edit'   => EditBook::route('/{record}/edit'),
        ];
    }
}
