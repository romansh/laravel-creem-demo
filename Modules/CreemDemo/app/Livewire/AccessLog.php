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
        $this->profile = session('creem_demo_active_profile', 'default');
        $this->loadAccesses();
    }

    public function loadAccesses(): void
    {
        $this->accesses = array_reverse(cache()->get("demo_accesses_{$this->profile}", []));
    }

    public function clearAccesses(): void
    {
        cache()->forget("demo_accesses_{$this->profile}");
        $this->accesses = [];
    }

    #[On('configuration-updated')]
    #[On('profile-switched')]
    public function refresh(): void
    {
        $this->profile = session('creem_demo_active_profile', 'default');
        $this->loadAccesses();
    }

    public function render()
    {
        return view('creemdemo::livewire.access-log');
    }
}
