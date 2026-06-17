<?php

use App\Http\Controllers\AuthController;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);

    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::get('/register/invite/{token}', [AuthController::class, 'showRegister'])->name('register.invite');
    Route::post('/register', [AuthController::class, 'register']);
});

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

Route::middleware([
    'auth',
])->group(function () {
    // Send users into the Filament customer panel (their tenant dashboard).
    Route::get('/dashboard', function () {
        $panel = Filament::getPanel('customer');
        $tenant = Auth::user()?->organizations()->first();

        return redirect($panel->getUrl($tenant) ?? $panel->getUrl());
    })->name('dashboard');
});
