<?php

namespace Modules\CreemDemo\Livewire;

use Livewire\Component;
use Livewire\Attributes\On;

/**
 * Webhook Logs — displays events captured by CreemDemoListener.
 *
 * Data written to per-profile CACHE key `demo_webhooks_{profile}` by the
 * listener when the package dispatches Laravel Events after verifying webhooks.
 * Cache is used (not session) because webhook requests arrive without browser session.
 *
 * This component is read-only — it does NOT own a webhook endpoint.
 */
class WebhookLogs extends Component
{
    public string $profile = 'default';
    public array $logs = [];
    public ?int  $expandedIndex = null;

    public function mount(): void
    {
        $this->profile = cache()->get(ConfigurationForm::getCacheActiveProfileKey(), 'default');
        $this->loadLogs();
    }

    public function loadLogs(): void
    {
        $sessionId = session()->getId();
        $this->logs = array_reverse(cache()->get("demo_webhooks_{$this->profile}_{$sessionId}", []));
    }

    public function toggleExpand(int $index): void
    {
        $this->expandedIndex = $this->expandedIndex === $index ? null : $index;
    }

    public function clearLogs(): void
    {
        $sessionId = session()->getId();
        cache()->forget("demo_webhooks_{$this->profile}_{$sessionId}");
        $this->logs         = [];
        $this->expandedIndex = null;
    }

    #[On('configuration-updated')]
    public function refresh(): void
    {
        $this->profile = cache()->get(ConfigurationForm::getCacheActiveProfileKey(), 'default');
        $this->loadLogs();
    }

    public function render()
    {
        return view('creemdemo::livewire.webhook-logs');
    }
}
