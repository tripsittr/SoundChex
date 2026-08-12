<?php

namespace App\Filament\Pages;

use App\Services\SettingsService;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class MetadataSettings extends Page
{
    protected string $view = 'filament.pages.metadata-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;
    protected static string|UnitEnum|null $navigationGroup = 'Settings';
    protected static ?string $navigationLabel = 'Metadata Sources';
    protected static ?int $navigationSort = 10;

    public ?array $data = [];

    public function mount(SettingsService $settings): void
    {
        foreach ($this->allRequiredSettings() as $key => $label) {
            $this->data[$key] = $settings->get($key);
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components($this->buildSections());
    }

    public function save(SettingsService $settings): void
    {
        $encryptedKeys = [
            'spotify_client_secret', 'discogs_token', 'lastfm_api_key',
            'genius_api_key', 'fanart_tv_api_key', 'tmdb_api_key',
            'omdb_api_key', 'trakt_client_secret', 'tvdb_api_key',
            'google_books_api_key', 'acoustid_api_key', 'musixmatch_api_key',
        ];

        foreach ($this->data as $key => $value) {
            // Pasted keys routinely carry a trailing newline or a stray space,
            // which the API rejects as an invalid key rather than as
            // whitespace.
            $value = is_string($value) ? trim($value) : $value;

            if (filled($value)) {
                $settings->set($key, $value, encrypt: in_array($key, $encryptedKeys, true));
            }
        }

        Notification::make()->title('API keys saved')->success()->send();
    }

    private function allRequiredSettings(): array
    {
        $all = [];

        foreach (config('metadata_sources') as $sources) {
            foreach ($sources as $class) {
                if (class_exists($class)) {
                    $all = array_merge($all, app($class)->requiredSettings());
                }
            }
        }

        return $all;
    }

    private function buildSections(): array
    {
        $sections = [];

        $groups = [
            'Music Sources'   => config('metadata_sources.music', []),
            'Movie Sources'   => config('metadata_sources.movie', []),
            'TV Show Sources' => config('metadata_sources.show', []),
            'Book Sources'    => config('metadata_sources.book', []),
            'Subtitles'       => config('metadata_sources.subtitle', []),
        ];

        foreach ($groups as $heading => $classes) {
            $fields = [];

            foreach ($classes as $class) {
                if (! class_exists($class)) {
                    continue;
                }

                $source   = app($class);
                $required = $source->requiredSettings();

                if (empty($required)) {
                    continue;
                }

                foreach ($required as $key => $label) {
                    $fields[] = TextInput::make($key)
                        ->label($label)
                        // Deliberately not ->password(): browsers treat a
                        // password field as a login and autofill it, which
                        // silently prepended "password" to a saved API key and
                        // produced a 401 that looked like a bad key.
                        ->autocomplete('off')
                        ->extraInputAttributes([
                            'data-1p-ignore' => 'true',
                            'data-lpignore' => 'true',
                            'data-bwignore' => 'true',
                        ])
                        ->helperText($source->name());
                }
            }

            if (! empty($fields)) {
                $sections[] = Section::make($heading)->schema($fields)->columns(2)->collapsed();
            }
        }

        return $sections;
    }
}
