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
        $this->profile = session('creem_demo_active_profile', 'default');
        $this->loadLogs();
    }

    public function loadLogs(): void
    {
        $this->logs = array_reverse(cache()->get("demo_webhooks_{$this->profile}", []));
    }

    public function toggleExpand(int $index): void
    {
        $this->expandedIndex = $this->expandedIndex === $index ? null : $index;
    }

    public function clearLogs(): void
    {
        cache()->forget("demo_webhooks_{$this->profile}");
        $this->logs         = [];
        $this->expandedIndex = null;
    }

    #[On('configuration-updated')]
    public function refresh(): void
    {
        $this->profile = session('creem_demo_active_profile', 'default');
        $this->loadLogs();
    }

    public function render()
    {
        return view('creemdemo::livewire.webhook-logs');
    }
}
