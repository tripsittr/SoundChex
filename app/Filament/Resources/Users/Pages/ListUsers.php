<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\OrganizationInvite;
use App\Notifications\OrganizationInviteNotification;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('inviteUser')
                ->label('Invite User')
                ->icon('heroicon-o-envelope')
                ->form([
                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->maxLength(255),
                    Select::make('role')
                        ->required()
                        ->options(config('organization_roles.assignable', []))
                        // Constrain server-side too: a crafted request must not
                        // be able to invite with a protected role (e.g. super_admin).
                        ->in(array_keys(config('organization_roles.assignable', []))),
                ])
                ->action(function (array $data): void {
                    $tenant = Filament::getTenant();

                    abort_unless($tenant !== null, 404);

                    $invite = OrganizationInvite::updateOrCreate(
                        [
                            'organization_id' => $tenant->getKey(),
                            'email' => strtolower($data['email']),
                            'accepted_at' => null,
                        ],
                        [
                            'role' => $data['role'],
                            'token' => Str::uuid()->toString(),
                            'invited_by' => Auth::id(),
                            'expires_at' => now()->addDays(7),
                        ],
                    );

                    Notification::route('mail', $invite->email)
                        ->notify(new OrganizationInviteNotification($invite));
                })
                ->successNotificationTitle('Invitation sent'),
        ];
    }
}
