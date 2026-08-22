<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Server-wide key/value settings, primarily API keys for metadata sources.
 *
 * One server, one set of settings — SoundChex is self-hosted, so there is no
 * per-tenant resolution to do.
 */
class SettingsService
{
    private const CACHE_TTL_MINUTES = 10;

    /**
     * Resolve a setting, falling back to config/.env when unset.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember(
            $this->cacheKey($key),
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            fn () => Setting::where('key', $key)->value('value') ?? $default,
        );
    }

    public function set(string $key, mixed $value, bool $encrypt = false): void
    {
        // The flag has to be set before the value: the model's mutator reads it
        // to decide whether to encrypt, and mass assignment applies attributes
        // in array order. Assigning them separately makes that explicit rather
        // than dependent on key order in a literal.
        $setting = Setting::firstOrNew(['key' => $key]);

        $setting->is_encrypted = $encrypt;
        $setting->value = $value;
        $setting->save();

        Cache::forget($this->cacheKey($key));
    }

    public function forget(string $key): void
    {
        Setting::where('key', $key)->delete();

        Cache::forget($this->cacheKey($key));
    }

    private function cacheKey(string $key): string
    {
        return "setting:{$key}";
    }
}
