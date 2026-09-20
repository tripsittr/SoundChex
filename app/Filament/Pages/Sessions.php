<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 SoundChex

namespace App\Filament\Pages;

use App\Filament\Concerns\RestrictsToServerAdmins;
use App\Models\MediaPlay;
use App\Models\Profile;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Laravel\Sanctum\PersonalAccessToken;
use UnitEnum;

/**
 * Who is signed in, and what they are listening to (S-36).
 *
 * Device *reports* say what went wrong on a device; this says who is using the
 * server right now — the signed-in devices (one access token each, naming a
 * device and a profile) and what each profile is currently playing. Device
 * tracking existed; the login and listening side did not.
 *
 * Read-only apart from revoking a token — signing a device out from here.
 */
class Sessions extends Page
{
    use RestrictsToServerAdmins;

    protected string $view = 'filament.pages.sessions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $title = 'Sessions';

    protected static ?string $navigationLabel = 'Sessions';

    /** A device is "active" if its token was used within this window. */
    private const ACTIVE_MINUTES = 15;

    /** @var array<int, array<string, mixed>> */
    public array $logins = [];

    /** @var array<int, array<string, mixed>> */
    public array $listening = [];

    public function mount(): void
    {
        $this->load();
    }

    public function load(): void
    {
        $this->logins = $this->loginSessions();
        $this->listening = $this->listeningSessions();
    }

    /**
     * The signed-in devices: one row per access token, each naming a device and
     * (through its ability) a profile.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loginSessions(): array
    {
        $profiles = Profile::pluck('name', 'id');

        return PersonalAccessToken::query()
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByRaw('last_used_at IS NULL, last_used_at DESC')
            ->limit(100)
            ->get()
            ->map(function (PersonalAccessToken $token) use ($profiles): array {
                $profileId = $this->profileIdFromAbilities($token->abilities ?? []);

                return [
                    'id' => $token->id,
                    'device' => $token->name ?: 'Unnamed device',
                    'profile' => $profileId !== null ? ($profiles[$profileId] ?? 'Profile '.$profileId) : '—',
                    'created' => $token->created_at?->diffForHumans(),
                    'last_used' => $token->last_used_at?->diffForHumans() ?? 'never',
                    'active' => $token->last_used_at !== null
                        && $token->last_used_at->gt(now()->subMinutes(self::ACTIVE_MINUTES)),
                ];
            })
            ->all();
    }

    /**
     * What each profile is playing now or played most recently — the newest play
     * row per profile, within the last day.
     *
     * @return array<int, array<string, mixed>>
     */
    private function listeningSessions(): array
    {
        return MediaPlay::query()
            ->with(['mediaItem', 'profile'])
            ->where('updated_at', '>', now()->subDay())
            ->latest('updated_at')
            ->get()
            // One entry per profile: the most recent play is what they are on.
            ->unique('profile_id')
            ->take(50)
            ->map(fn (MediaPlay $play): array => [
                'id' => $play->id,
                'profile' => $play->profile?->name ?? 'Unknown profile',
                'title' => $play->mediaItem?->title ?? 'Unknown item',
                'position' => $this->clock($play->position_seconds),
                'source' => $play->source ?: '—',
                'when' => $play->updated_at?->diffForHumans(),
                'active' => $play->updated_at?->gt(now()->subMinutes(self::ACTIVE_MINUTES)) ?? false,
            ])
            ->values()
            ->all();
    }

    /** The profile id baked into a token's abilities ("profile:3"), or null. */
    private function profileIdFromAbilities(array $abilities): ?int
    {
        foreach ($abilities as $ability) {
            if (is_string($ability) && str_starts_with($ability, 'profile:')) {
                return (int) substr($ability, strlen('profile:'));
            }
        }

        return null;
    }

    /** Seconds as m:ss / h:mm:ss for a resume position. */
    private function clock(?int $seconds): string
    {
        if ($seconds === null || $seconds <= 0) {
            return '—';
        }

        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return $h > 0
            ? sprintf('%d:%02d:%02d', $h, $m, $s)
            : sprintf('%d:%02d', $m, $s);
    }

    /**
     * Sign one device out by revoking its token.
     */
    public function revoke(int $tokenId): void
    {
        PersonalAccessToken::whereKey($tokenId)->delete();

        $this->load();

        Notification::make()->title('Device signed out')->success()->send();
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh')
                ->icon(Heroicon::OutlinedArrowPath)
                ->action(function (): void {
                    $this->load();

                    Notification::make()
                        ->title(count($this->logins).' signed-in '.str('device')->plural(count($this->logins)))
                        ->success()
                        ->send();
                }),
        ];
    }
}
