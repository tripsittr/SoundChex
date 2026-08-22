<?php

namespace App\Filament\Resources\Profiles\Pages;

use App\Filament\Resources\Profiles\ProfileResource;
use App\Models\Profile;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProfiles extends ListRecords
{
    protected static string $resource = ProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add profile')
                ->mutateDataUsing(function (array $data): array {
                    // Profiles belong to an account; the form doesn't ask
                    // because a self-hosted server has one household.
                    $data['user_id'] ??= auth()->id();
                    $data['sort_order'] ??= Profile::where('user_id', $data['user_id'])->max('sort_order') + 1;

                    return $data;
                }),
        ];
    }
}
