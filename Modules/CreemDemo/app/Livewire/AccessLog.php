<?php

namespace Modules\CreemDemo\Livewire;

use Livewire\Component;
use Livewire\Attributes\On;

/**
 * Access Log — shows GrantAccess / RevokeAccess events written by CreemDemoListener.
 *
 * GrantAccess fires on:  checkout.completed, subscription.paid
 * RevokeAccess fires on: subscription.canceled, subscription.expired
 *
 * Entries are created by real webhook events — no simulation buttons.
 */
class AccessLog extends Component
{
    public string $profile = 'default';
    public array $accesses = [];

    public function mount(): void
    {
        $this->profile = cache()->get(ConfigurationForm::getCacheActiveProfileKey(), 'default');
        $this->loadAccesses();
    }

    public function loadAccesses(): void
    {
        $sessionId = session()->getId();
        $this->accesses = array_reverse(cache()->get("demo_accesses_{$this->profile}_{$sessionId}", []));
    }

    public function clearAccesses(): void
    {
        $sessionId = session()->getId();
        cache()->forget("demo_accesses_{$this->profile}_{$sessionId}");
        $this->accesses = [];
    }

    #[On('configuration-updated')]
    #[On('profile-switched')]
    public function refresh(): void
    {
        $this->profile = cache()->get(ConfigurationForm::getCacheActiveProfileKey(), 'default');
        $this->loadAccesses();
    }

    public function render()
    {
        return view('creemdemo::livewire.access-log');
    }
}
