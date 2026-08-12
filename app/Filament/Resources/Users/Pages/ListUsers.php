<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Self-hosted: the server owner creates accounts directly rather
            // than emailing invitations into a tenant.
            CreateAction::make()->label('Add user'),
        ];
    }
}
