<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class SyncPermissionTeamFromFilamentTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = Filament::getTenant()?->getKey();

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        return $next($request);
    }
}
