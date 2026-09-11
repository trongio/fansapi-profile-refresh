<?php

namespace App\Refresh\Clients;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Where the signing parameters come from.
 *
 * OnlyFans rotates them; the community re-publishes them. We fetch from the
 * configured URL, cache for an hour, and fall back to the copy committed in
 * fixtures/ so the client still runs offline. The x-bc device id is generated
 * once and cached, exactly as the web client stores its own in localStorage.
 */
class OnlyfansRules
{
    public const CACHE_KEY = 'fansapi:onlyfans:dynamic-rules';

    public const DEVICE_KEY = 'fansapi:onlyfans:x-bc';

    /** @return array<string,mixed> */
    public function current(): array
    {
        return Cache::remember(self::CACHE_KEY, (int) config('fansapi.onlyfans.rules_ttl_seconds'), function (): array {
            $url = (string) config('fansapi.onlyfans.rules_url');

            if ($url !== '') {
                try {
                    $fetched = Http::timeout(5)->get($url)->throw()->json();
                    if ($this->valid($fetched)) {
                        return $fetched + ['_source' => 'remote'];
                    }
                } catch (\Throwable) {
                    // fall through to the committed copy
                }
            }

            // Optional committed fallback for offline runs. Absent by default;
            // the rules are normally fetched from the configured URL above.
            $path = base_path('fixtures/onlyfans-dynamic-rules.json');
            $local = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

            return ($this->valid($local) ? $local : []) + ['_source' => $local ? 'fixture' : 'none'];
        });
    }

    /** Force a refetch, used after the upstream reports a stale signature. */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function deviceId(): string
    {
        return Cache::rememberForever(self::DEVICE_KEY, fn () => Str::lower(Str::random(40)));
    }

    private function valid(mixed $rules): bool
    {
        return is_array($rules)
            && isset($rules['static_param'], $rules['prefix'], $rules['suffix'], $rules['app-token'])
            && is_array($rules['checksum_indexes'] ?? null)
            && is_int($rules['checksum_constant'] ?? null);
    }
}
