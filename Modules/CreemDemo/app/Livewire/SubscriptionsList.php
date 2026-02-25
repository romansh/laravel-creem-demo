<?php

namespace Modules\CreemDemo\Livewire;

use Livewire\Component;
use Livewire\Attributes\On;
use Romansh\LaravelCreem\Facades\Creem;
use Faker\Factory as Faker;

class SubscriptionsList extends Component
{
    public string  $profile             = 'default';
    public array   $products            = [];
    public array   $activeSubscriptions = [];
    public bool    $loading             = true;
    public ?string $error               = null;
    public bool    $isConfigured        = false;

    // Create preview popup
    public bool  $showCreateModal = false;
    public array $draftPlan       = [];

    /** All valid Creem billing periods */
    public const BILLING_PERIODS = [
        'every-month'        => 'Monthly',
        'every-three-months' => 'Quarterly (3 months)',
        'every-six-months'   => 'Bi-annual (6 months)',
        'every-year'         => 'Annual',
    ];

    protected function checkAndApplyConfig(): void
    {
        $this->profile = session('creem_demo_active_profile', 'default');
        ConfigurationForm::applySessionConfig();
        $config = session('creem_demo_config', []);
        $this->isConfigured = !empty($config[$this->profile]['api_key']);
    }

    public function mount(): void
    {
        $this->checkAndApplyConfig();
        // Defer API call to wire:init="loadSubscriptions"
    }

    public function loadSubscriptions(): void
    {
        $this->loading = true;
        $this->error   = null;
        $this->checkAndApplyConfig();

        if (!$this->isConfigured) { $this->products = []; $this->loading = false; return; }

        try {
            $response       = Creem::profile($this->profile)->products()->list();
            $this->products = array_values(array_filter(
                $response['items'] ?? [],
                fn($p) => ($p['billing_type'] ?? '') === 'recurring'
            ));
            $this->activeSubscriptions = session("demo_subscriptions_{$this->profile}", []);
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        } finally {
            $this->loading = false;
        }
    }

    /** Generate random plan draft and show preview modal */
    public function prepareCreate(): void
    {
        $faker   = Faker::create();
        $tiers   = ['Starter', 'Basic', 'Pro', 'Business', 'Enterprise', 'Team', 'Growth', 'Scale'];
        $period  = array_rand(self::BILLING_PERIODS);
        $prices  = [499, 999, 1499, 1999, 2999, 4999, 7999, 9999];

        $this->draftPlan = [
            'name'           => $faker->randomElement($tiers) . ' Plan',
            'description'    => 'Includes full feature access, priority support, and unlimited usage. Billed ' . strtolower(self::BILLING_PERIODS[$period]) . '.',
            'price'          => $prices[array_rand($prices)],
            'currency'       => 'USD',
            'billing_type'   => 'recurring',
            'billing_period' => $period,
            'tax_mode'       => 'inclusive',
            'tax_category'   => 'saas',
        ];
        $this->showCreateModal = true;
    }

    public function regenerateDraft(): void
    {
        $this->prepareCreate();
    }

    public function confirmCreate(): void
    {
        $this->checkAndApplyConfig();
        if (!$this->isConfigured) return;

        try {
            Creem::profile($this->profile)->products()->create($this->draftPlan);
            session()->flash('success', "Plan \"{$this->draftPlan['name']}\" created!");
            $this->showCreateModal = false;
            $this->loadSubscriptions();
            $this->dispatch('configuration-updated');
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function cancelCreate(): void
    {
        $this->showCreateModal = false;
        $this->draftPlan = [];
    }

    public function subscribe(string $productId): void
    {
        $this->checkAndApplyConfig();
        try {
            $faker    = Faker::create();
            $checkout = Creem::profile($this->profile)->checkouts()->create([
                'product_id'  => $productId,
                'customer'    => ['email' => $faker->safeEmail()],
                'success_url' => route('creem-demo.success') . '?type=subscription',
            ]);
            $url = $checkout['checkout_url'] ?? null;
            if (!$url) {
                session()->flash('error', 'Checkout URL not returned — check API key or product status.');
                return;
            }
            $this->dispatch("open-url", url: $url);
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function pauseSubscription(int $index): void
    {
        $subs = session("demo_subscriptions_{$this->profile}", []);
        if (!isset($subs[$index])) return;
        if ($subId = $subs[$index]['subscription_id'] ?? null) {
            try { $this->checkAndApplyConfig(); Creem::profile($this->profile)->subscriptions()->pause($subId); }
            catch (\Throwable $e) { session()->flash('error', $e->getMessage()); }
        }
        $subs[$index]['status'] = 'paused';
        session(["demo_subscriptions_{$this->profile}" => $subs]);
        $this->activeSubscriptions = $subs;
    }

    public function resumeSubscription(int $index): void
    {
        $subs = session("demo_subscriptions_{$this->profile}", []);
        if (!isset($subs[$index])) return;
        if ($subId = $subs[$index]['subscription_id'] ?? null) {
            try { $this->checkAndApplyConfig(); Creem::profile($this->profile)->subscriptions()->resume($subId); }
            catch (\Throwable $e) { session()->flash('error', $e->getMessage()); }
        }
        $subs[$index]['status'] = 'active';
        session(["demo_subscriptions_{$this->profile}" => $subs]);
        $this->activeSubscriptions = $subs;
    }

    public function cancelSubscription(int $index): void
    {
        $subs = session("demo_subscriptions_{$this->profile}", []);
        if (!isset($subs[$index])) return;
        if ($subId = $subs[$index]['subscription_id'] ?? null) {
            try { $this->checkAndApplyConfig(); Creem::profile($this->profile)->subscriptions()->cancel($subId); }
            catch (\Throwable $e) { session()->flash('error', $e->getMessage()); }
        }
        $subs[$index]['status'] = 'cancelled';
        session(["demo_subscriptions_{$this->profile}" => $subs]);
        $this->activeSubscriptions = $subs;
    }

    #[On('configuration-updated')]
    #[On('profile-switched')]
    public function refreshFromConfig(): void
    {
        $this->checkAndApplyConfig();
        $this->loadSubscriptions();
    }

    public function render()
    {
        return view('creemdemo::livewire.subscriptions-list');
    }
}
