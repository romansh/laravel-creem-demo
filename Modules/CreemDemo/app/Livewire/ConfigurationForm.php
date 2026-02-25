<?php

namespace Modules\CreemDemo\Livewire;

use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Configuration form — multi-profile API credential manager.
 *
 * Supports adding/removing profiles, switching active profile.
 * Every saved profile is stored in session AND in the cache under a unique token key.
 * The same token forms the per-profile webhook URL: /creem/hook/{token}
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

    /** Cache TTL in seconds (10 minutes) */
    const CACHE_TTL = 600;

    /** Cache key prefix */
    const CACHE_PREFIX = 'creem_session_';

    public function mount(): void
    {
        $this->loadFromSession();
    }

    protected function loadFromSession(): void
    {
        $config = session('creem_demo_config', []);
        $this->activeProfile = session('creem_demo_active_profile', 'default');

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
        $this->webhookUrl    = $p['webhook_url']     ?? '';
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
                    'test_mode'      => true,
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
        $tunnel = env('CLOUDFLARED_TUNNEL_DOMAIN');
        $base   = $tunnel
            ? rtrim($tunnel, '/')
            : rtrim(config('app.url', request()->getSchemeAndHttpHost()), '/');
        return $base . '/creem/hook/' . $cacheKey;
    }

    public function switchProfile(string $name): void
    {
        // Save current before switching
        $this->saveCurrentProfileToMemory();
        $this->saveAllToSession();

        $this->activeProfile = $name;
        session(['creem_demo_active_profile' => $name]);
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

        $this->profiles[$slug] = ['api_key' => '', 'webhook_secret' => '', 'cache_key' => '', 'webhook_url' => ''];
        $this->newProfileName = '';
        $this->saveAllToSession();
        $this->switchProfile($slug);
    }

    public function removeProfile(string $name): void
    {
        if ($name === 'default' || !isset($this->profiles[$name])) return;

        // Delete from cache
        $key = $this->profiles[$name]['cache_key'] ?? '';
        if ($key) cache()->forget(self::CACHE_PREFIX . $key);

        // Remove any per-profile demo data (webhook logs stored in cache, rest in session)
        cache()->forget("demo_webhooks_{$name}");
        session()->forget(["demo_accesses_{$name}", "demo_captured_licenses_{$name}", "demo_discounts_{$name}", "demo_subscriptions_{$name}"]);

        unset($this->profiles[$name]);

        if ($this->activeProfile === $name) {
            $this->activeProfile = 'default';
            session(['creem_demo_active_profile' => 'default']);
        }

        $this->saveAllToSession();
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
        ], self::CACHE_TTL);

        $this->saveAllToSession();
        self::applySessionConfig();

        session()->flash('success', "Profile \"{$this->activeProfile}\" saved!");
        $this->dispatch('configuration-updated');
    }

    protected function saveAllToSession(): void
    {
        session(['creem_demo_config' => $this->profiles]);
    }

    public function clearConfiguration(): void
    {
        // Remove all cached profile data
        foreach ($this->profiles as $data) {
            $k = $data['cache_key'] ?? '';
            if ($k) cache()->forget(self::CACHE_PREFIX . $k);
        }
        session()->forget(['creem_demo_config', 'creem_demo_active_profile']);
        $this->profiles      = ['default' => ['api_key' => '', 'webhook_secret' => '', 'cache_key' => '', 'webhook_url' => '']];
        $this->activeProfile = 'default';
        $this->apiKey        = '';
        $this->webhookSecret = '';
        $this->webhookUrl    = '';
        $this->dispatch('configuration-updated');
    }

    public function clearSession(): void
    {
        // Remove all cached profile data first
        foreach ($this->profiles as $data) {
            $k = $data['cache_key'] ?? '';
            if ($k) cache()->forget(self::CACHE_PREFIX . $k);
        }

        // Forget global config keys
        session()->forget(['creem_demo_config', 'creem_demo_active_profile']);

        // Forget any per-profile demo keys (webhook logs in cache, rest in session)
        $profiles = array_keys(session('creem_demo_config', $this->profiles ?? ['default' => []]));
        foreach ($profiles as $p) {
            cache()->forget("demo_webhooks_{$p}");
            session()->forget([
                "demo_accesses_{$p}", "demo_captured_licenses_{$p}",
                "demo_discounts_{$p}", "demo_subscriptions_{$p}",
            ]);
        }

        // Also clear legacy flat keys if present (except per-profile webhook keys)
        session()->forget(['demo.accesses', 'demo.captured_licenses', 'demo.discounts', 'demo_subscriptions']);
        $this->profiles      = ['default' => ['api_key' => '', 'webhook_secret' => '', 'cache_key' => '', 'webhook_url' => '']];
        $this->activeProfile = 'default';
        $this->apiKey        = '';
        $this->webhookSecret = '';
        $this->webhookUrl    = '';
        session()->flash('success', 'Demo session fully cleared.');
        $this->dispatch('configuration-updated');
    }

    /**
     * Apply session config to Laravel runtime config.
     * Called statically by other components on mount.
     */
    public static function applySessionConfig(): void
    {
        $config = session('creem_demo_config', []);
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
