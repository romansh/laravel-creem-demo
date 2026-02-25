<?php

namespace Modules\CreemDemo\Livewire;

use Romansh\LaravelCreem\Facades\Creem;
use Livewire\Component;
use Livewire\Attributes\On;

class DashboardStats extends Component
{
    public string $profile              = 'default';
    public int    $totalProducts        = 0;
    public int    $onetimeProducts      = 0;
    public int    $subscriptionProducts = 0;
    public bool   $loading              = false;
    public bool   $isConfigured         = false;

    public function mount(): void
    {
        $this->profile = session('creem_demo_active_profile', 'default');
        $config = session('creem_demo_config', []);
        $this->isConfigured = !empty($config[$this->profile]['api_key']);
        $this->loading = $this->isConfigured;
    }

    public function loadStats(): void
    {
        $this->profile = session('creem_demo_active_profile', 'default');
        ConfigurationForm::applySessionConfig();
        $config = session('creem_demo_config', []);
        $this->isConfigured = !empty($config[$this->profile]['api_key']);

        if (!$this->isConfigured) {
            $this->resetStats();
            $this->loading = false;
            $this->dispatch('stats-loaded', configured: false);
            return;
        }

        $this->loading = true;
        try {
            // Fetch a small page of products (50 items) and compute accurate breakdowns locally.
            // This is a compromise between accuracy and speed.
            $response = Creem::profile($this->profile)->products()->list(1, 50);
            $products = $response['items'] ?? [];

            $this->totalProducts        = $response['total'] ?? count($products);
            $this->onetimeProducts      = count(array_filter($products, fn($p) => ($p['billing_type'] ?? '') === 'onetime'));
            $this->subscriptionProducts = count(array_filter($products, fn($p) => ($p['billing_type'] ?? '') === 'recurring'));
        } catch (\Throwable $e) {
            $this->resetStats();
        } finally {
            $this->loading = false;
            $this->dispatch('stats-loaded', configured: $this->isConfigured);
        }
    }

    protected function resetStats(): void
    {
        $this->totalProducts        = 0;
        $this->onetimeProducts      = 0;
        $this->subscriptionProducts = 0;
    }

    #[On('configuration-updated')]
    #[On('profile-switched')]
    public function refresh(): void
    {
        $this->loadStats();
    }

    public function render()
    {
        return view('creemdemo::livewire.dashboard-stats');
    }
}
