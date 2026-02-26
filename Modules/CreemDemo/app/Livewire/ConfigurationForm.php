<?php

namespace Modules\CreemDemo\Livewire;

use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Configuration form — multi-profile API credential manager.
 *
 * Supports adding/removing profiles, switching active profile.
 * Every saved profile is stored in CACHE under session-specific keys for isolation.
 * Webhook tokens are stored separately under unique cache keys per profile.
 * The cache key forms the per-profile webhook URL: /creem/hook/{token}
 */
class ConfigurationForm extends Component
{
    public string $activeProfile = 'default';
    public array  $profiles      = [];   // [name => [api_key, webhook_secret, cache_key, webhook_url]]
    public string $newProfileName = '';

    // Fields for the currently editing profile
    public string $apiKey        = '';
    public string $webhookSecret = '';
    public string $webhookUrl    = '';   // generated, shown in UI

    /** Cache TTL in seconds (2 hours) */
    const CACHE_TTL = 7200;

    /** Cache key prefix for webhook tokens */
    const CACHE_PREFIX = 'creem_session_';

    /**
     * Get session-specific cache key for profile configs.
     * Each browser session gets its own isolated config storage.
     */
    public static function getCacheConfigKey(): string
    {
        return 'creem_demo_config_' . session()->getId();
    }

    /**
     * Get session-specific cache key for active profile.
     * Each browser session gets its own isolated active profile.
     */
    public static function getCacheActiveProfileKey(): string
    {
        return 'creem_demo_active_profile_' . session()->getId();
    }

    public function mount(): void
    {
        $this->loadFromCache();
    }

    protected function loadFromCache(): void
    {
        $config = cache()->get(self::getCacheConfigKey(), []);
        $this->activeProfile = cache()->get(self::getCacheActiveProfileKey(), 'default');

        if (!empty($config)) {
            $this->profiles = $config;
        } else {
            $this->profiles = ['default' => ['api_key' => '', 'webhook_secret' => '', 'cache_key' => '', 'webhook_url' => '']];
        }

        $this->loadProfileFields();
    }

    protected function loadProfileFields(): void
    {
        $p = $this->profiles[$this->activeProfile] ?? [];
        $this->apiKey        = $p['api_key']        ?? '';
        $this->webhookSecret = $p['webhook_secret']  ?? '';

        // Generate webhook URL immediately if not yet set
        $this->ensureWebhookUrl();
    }

    /**
     * Ensure the active profile has a cache key and webhook URL.
     * Called on mount, profile switch, and profile creation — before credentials are entered.
     * Does NOT overwrite an existing webhook_url (preserves user edits).
     */
    protected function ensureWebhookUrl(): void
    {
        $profile = $this->profiles[$this->activeProfile] ?? [];
        $cacheKey = $profile['cache_key'] ?? '';

        if (empty($cacheKey)) {
            $cacheKey = Str::uuid()->toString();
            $this->profiles[$this->activeProfile]['cache_key'] = $cacheKey;
        }

        // Only generate URL if not already set (preserve user-edited URLs)
        $existingUrl = $profile['webhook_url'] ?? '';
        if (empty($existingUrl)) {
            $existingUrl = self::buildWebhookUrl($cacheKey);
            $this->profiles[$this->activeProfile]['webhook_url'] = $existingUrl;
        }

        $this->webhookUrl = $existingUrl;
        $this->saveAllToCache();
    }

    /** Update webhook URL from the UI (must be unique across profiles). */
    public function updateWebhook(string $newUrl): void
    {
        $newUrl = trim($newUrl);
        if (empty($newUrl)) {
            session()->flash('error', 'Webhook URL cannot be empty.');
            return;
        }

        // Extract token (last path segment) as cache key
        $path = parse_url($newUrl, PHP_URL_PATH) ?: '';
        $token = trim(basename($path));
        if (empty($token)) {
            session()->flash('error', 'Invalid webhook URL. It must include a token path segment.');
            return;
        }

        // Prevent duplicates across profiles
        foreach ($this->profiles as $name => $p) {
            if ($name === $this->activeProfile) continue;
            if (($p['webhook_url'] ?? '') === $newUrl || (!empty($p['cache_key']) && $p['cache_key'] === $token)) {
                session()->flash('error', "Webhook URL collides with profile '{$name}'. Choose a different URL.");
                return;
            }
        }

        $oldKey = $this->profiles[$this->activeProfile]['cache_key'] ?? '';

        // Move cached profile data from old key to new key
        if (!empty($oldKey) && $oldKey !== $token && cache()->has(self::CACHE_PREFIX . $oldKey)) {
            $entry = cache()->get(self::CACHE_PREFIX . $oldKey);
            if (is_array($entry)) {
                $entry['session_id'] = session()->getId();
                cache()->put(self::CACHE_PREFIX . $token, $entry, self::CACHE_TTL);
                cache()->forget(self::CACHE_PREFIX . $oldKey);
            }
        }

        // Save new values
        $this->profiles[$this->activeProfile]['cache_key'] = $token;
        $this->profiles[$this->activeProfile]['webhook_url'] = $newUrl;
        $this->webhookUrl = $newUrl;

        $this->saveAllToCache();
        session()->flash('success', 'Webhook URL updated.');
        $this->dispatch('configuration-updated');
    }

    /** Refresh cache TTL for all saved profiles — called via wire:poll every 9 minutes. */
    public function keepAlive(): void
    {
        foreach ($this->profiles as $name => $data) {
            $key = $data['cache_key'] ?? '';
            if ($key && !empty($data['api_key'])) {
                cache()->put(self::CACHE_PREFIX . $key, [
                    'profile_name'   => $name,
                    'api_key'        => $data['api_key'],
                    'webhook_secret' => $data['webhook_secret'] ?? '',
                    'test_mode'      => $data['test_mode'] ?? true,
                    'session_id'     => session()->getId(),
                ], self::CACHE_TTL);
            }
        }
    }

    /** Generate or return existing cache key for a profile. */
    protected function ensureCacheKey(string $profileName): string
    {
        $existing = $this->profiles[$profileName]['cache_key'] ?? '';
        return $existing ?: Str::uuid()->toString();
    }

    /** Build the public-facing webhook URL for a given cache key. */
    public static function buildWebhookUrl(string $cacheKey): string
    {
        $appUrl = config('app.url', request()->getSchemeAndHttpHost());
        $tunnel = env('CLOUDFLARED_TUNNEL_DOMAIN');
        // If app runs on localhost and Cloudflare Tunnel is configured, use tunnel domain
        $base = (str_contains($appUrl, 'localhost') && $tunnel)
            ? rtrim($tunnel, '/')
            : rtrim($appUrl, '/');
        return $base . '/creem/hook/' . $cacheKey;
    }

    public function switchProfile(string $name): void
    {
        // Save current before switching
        $this->saveCurrentProfileToMemory();
        $this->saveAllToCache();

        $this->activeProfile = $name;
        cache()->put(self::getCacheActiveProfileKey(), $name, self::CACHE_TTL);
        $this->loadProfileFields();

        $this->dispatch('profile-switched', name: $name);
        $this->dispatch('configuration-updated');
    }

    public function addProfile(): void
    {
        $name = trim($this->newProfileName);
        if (empty($name)) return;

        $slug = preg_replace('/[^a-z0-9_]/', '_', strtolower($name));
        if (isset($this->profiles[$slug])) {
            session()->flash('error', "Profile \"{$slug}\" already exists.");
            return;
        }

        $cacheKey = Str::uuid()->toString();
        $this->profiles[$slug] = [
            'api_key'        => '',
            'webhook_secret' => '',
            'cache_key'      => $cacheKey,
            'webhook_url'    => self::buildWebhookUrl($cacheKey),
        ];
        $this->newProfileName = '';
        $this->saveAllToCache();
        $this->switchProfile($slug);
    }

    public function removeProfile(string $name): void
    {
        if ($name === 'default' || !isset($this->profiles[$name])) return;

        // Delete webhook token from cache
        $key = $this->profiles[$name]['cache_key'] ?? '';
        if ($key) cache()->forget(self::CACHE_PREFIX . $key);

        // Remove all per-profile demo data from cache
        $sessionId = session()->getId();
        foreach ([
            "demo_webhooks_{$name}_{$sessionId}",
            "demo_accesses_{$name}_{$sessionId}",
            "demo_captured_licenses_{$name}_{$sessionId}",
            "demo_discounts_{$name}_{$sessionId}",
            "demo_subscriptions_{$name}_{$sessionId}",
        ] as $cacheKey) {
            cache()->forget($cacheKey);
        }

        unset($this->profiles[$name]);

        if ($this->activeProfile === $name) {
            $this->activeProfile = 'default';
            cache()->put(self::getCacheActiveProfileKey(), 'default', self::CACHE_TTL);
        }

        $this->saveAllToCache();
        $this->loadProfileFields();
        $this->dispatch('configuration-updated');
    }

    protected function saveCurrentProfileToMemory(): void
    {
        $cacheKey = $this->ensureCacheKey($this->activeProfile);
        $this->profiles[$this->activeProfile] = [
            'api_key'        => $this->apiKey,
            'webhook_secret' => $this->webhookSecret,
            'test_mode'      => true,
            'cache_key'      => $cacheKey,
            'webhook_url'    => $this->profiles[$this->activeProfile]['webhook_url'] ?? '',
        ];
    }

    public function saveConfiguration(): void
    {
        if (empty($this->apiKey)) {
            session()->flash('error', 'API Key is required.');
            return;
        }

        // Generate / keep cache key and build webhook URL
        $cacheKey = $this->ensureCacheKey($this->activeProfile);
        $webhookUrl = self::buildWebhookUrl($cacheKey);

        $this->profiles[$this->activeProfile] = [
            'api_key'        => $this->apiKey,
            'webhook_secret' => $this->webhookSecret,
            'test_mode'      => true,
            'cache_key'      => $cacheKey,
            'webhook_url'    => $webhookUrl,
        ];
        $this->webhookUrl = $webhookUrl;

        // Persist profile data to cache (accessible by DemoWebhookController without session)
        cache()->put(self::CACHE_PREFIX . $cacheKey, [
            'profile_name'   => $this->activeProfile,
            'api_key'        => $this->apiKey,
            'webhook_secret' => $this->webhookSecret,
            'test_mode'      => true,
            'session_id'     => session()->getId(), // Store session ID for data isolation
        ], self::CACHE_TTL);

        $this->saveAllToCache();
        self::applyCacheConfig();

        session()->flash('success', "Profile \"{$this->activeProfile}\" saved!");
        $this->dispatch('configuration-updated');
    }

    protected function saveAllToCache(): void
    {
        cache()->put(self::getCacheConfigKey(), $this->profiles, self::CACHE_TTL);
    }

    public function clearConfiguration(): void
    {
        // Remove all cached profile data
        foreach ($this->profiles as $data) {
            $k = $data['cache_key'] ?? '';
            if ($k) cache()->forget(self::CACHE_PREFIX . $k);
        }
        cache()->forget(self::getCacheConfigKey());
        cache()->forget(self::getCacheActiveProfileKey());
        $this->profiles      = ['default' => ['api_key' => '', 'webhook_secret' => '', 'cache_key' => '', 'webhook_url' => '']];
        $this->activeProfile = 'default';
        $this->apiKey        = '';
        $this->webhookSecret = '';
        $this->ensureWebhookUrl();
        $this->dispatch('configuration-updated');
    }

    public function clearCache(): void
    {
        // Remove all cached profile data first
        foreach ($this->profiles as $data) {
            $k = $data['cache_key'] ?? '';
            if ($k) cache()->forget(self::CACHE_PREFIX . $k);
        }

        $profiles = array_keys($this->profiles ?: ['default' => []]);

        // Forget global config keys
        cache()->forget(self::getCacheConfigKey());
        cache()->forget(self::getCacheActiveProfileKey());

        // Forget all per-profile demo keys (now all in cache)
        $sessionId = session()->getId();
        foreach ($profiles as $p) {
            foreach ([
                "demo_webhooks_{$p}_{$sessionId}",
                "demo_accesses_{$p}_{$sessionId}",
                "demo_captured_licenses_{$p}_{$sessionId}",
                "demo_discounts_{$p}_{$sessionId}",
                "demo_subscriptions_{$p}_{$sessionId}",
            ] as $cacheKey) {
                cache()->forget($cacheKey);
            }
        }

        // Reset to defaults
        $this->profiles      = ['default' => ['api_key' => '', 'webhook_secret' => '', 'cache_key' => '', 'webhook_url' => '']];
        $this->activeProfile = 'default';
        $this->apiKey        = '';
        $this->webhookSecret = '';
        $this->ensureWebhookUrl();
        session()->flash('success', 'Demo cache fully cleared.');
        $this->dispatch('configuration-updated');
    }

    /**
     * Apply cache config to Laravel runtime config.
     * Called statically by other components on mount.
     */
    public static function applyCacheConfig(): void
    {
        $config = cache()->get(self::getCacheConfigKey(), []);
        if (empty($config)) return;

        foreach ($config as $profileName => $profileConfig) {
            if (empty($profileConfig['api_key'])) continue;
            config([
                "creem.profiles.{$profileName}.api_key"        => $profileConfig['api_key'],
                "creem.profiles.{$profileName}.webhook_secret"  => $profileConfig['webhook_secret'] ?? '',
                "creem.profiles.{$profileName}.test_mode"       => true,
            ]);
        }
    }

    public function render()
    {
        return view('creemdemo::livewire.configuration-form');
    }
}
