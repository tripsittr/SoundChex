<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Organizations\OrganizationResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Organization;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class OrganizationWelcome extends Widget
{
    protected string $view = 'filament.widgets.organization-welcome';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -1;

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();

        return [
            'userName' => Auth::user()?->name,
            'organizationName' => $tenant?->name,
            'inviteUrl' => $tenant ? UserResource::getUrl('index', tenant: $tenant) : null,
            'organizationUrl' => $this->organizationUrl($tenant),
        ];
    }

    protected function organizationUrl(?Organization $tenant): ?string
    {
        if (! $tenant) {
            return null;
        }

        // The view page is currently disabled, so link members to the edit screen.
        return OrganizationResource::getUrl('edit', ['record' => $tenant], tenant: $tenant);
    }
}
