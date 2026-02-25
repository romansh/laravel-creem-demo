<?php

namespace Modules\CreemDemo\Livewire;

use Livewire\Component;
use Livewire\Attributes\On;
use Romansh\LaravelCreem\Facades\Creem;

class DiscountDemo extends Component
{
    public array   $discounts    = [];
    public bool    $loading      = false;
    public ?string $error        = null;
    public bool    $isConfigured = false;
    public string  $profile      = 'default';

    // product selection for discount
    public array  $products = [];
    public array $selectedProducts = [];  // Changed to array for multi-select
    public array $productMap = [];

    public string $discountName   = '';
    public string $discountType   = 'percentage';
    public int    $discountAmount = 20;
    public string $discountCode   = '';
    public string $discountDuration = 'once'; // once | forever
    public bool   $showForm       = false;
    public bool   $showModal      = false;

    public function mount(): void
    {
        $this->profile = cache()->get(ConfigurationForm::getCacheActiveProfileKey(), 'default');
        $this->checkConfig();
        $sessionId = session()->getId();
        $this->discounts = cache()->get("demo_discounts_{$this->profile}_{$sessionId}", []);
        // Defer API call to wire:init="loadProducts"
        // Pre-populate product map from any previously loaded products in cache (best-effort)
    }

    protected function checkConfig(): void
    {
        $this->profile = cache()->get(ConfigurationForm::getCacheActiveProfileKey(), 'default');
        ConfigurationForm::applyCacheConfig();
        $config = cache()->get(ConfigurationForm::getCacheConfigKey(), []);
        $this->isConfigured = !empty($config[$this->profile]['api_key']);
    }

    public function loadProducts(): void
    {
        $this->products = [];
        try {
            ConfigurationForm::applyCacheConfig();
            $resp = Creem::profile($this->profile)->products()->list(1, 100);
            $this->products = $resp['items'] ?? [];
            // Build id -> name map for display
            $map = [];
            foreach ($this->products as $p) {
                $map[$p['id']] = $p['name'] ?? ($p['id'] ?? '');
            }
            $this->productMap = $map;
        } catch (\Throwable $e) {
            // ignore
        }
    }

    public function createDiscount(): void
    {
        $this->checkConfig();
        if (!$this->isConfigured) return;

        if (empty($this->selectedProducts)) {
            $this->error = 'Product selection is required.';
            $this->loading = false;
            return;
        }

        $this->loading = true;
        $this->error   = null;

        try {
            $code = strtoupper($this->discountCode ?: 'DEMO' . rand(10, 99));
            $name = $this->discountName ?: 'Demo Discount';

            // Creem API requires:
            // - percentage discounts: use 'percentage' key
            // - fixed discounts: use 'amount' key + 'currency'
            // - both require 'duration': 'once' | 'forever'
            $payload = [
                'name'     => $name,
                'code'     => $code,
                'type'     => $this->discountType,
                'duration' => $this->discountDuration,
                'currency' => 'USD',
            ];

            if ($this->discountType === 'percentage') {
                $payload['percentage'] = $this->discountAmount;
            } else {
                $payload['amount'] = $this->discountAmount;
            }

            // Support multiple products: store as array in session and send the
            // full list to the API using the `applies_to_products` field.
            $productIds = $this->selectedProducts;
            if (!empty($productIds)) {
                $payload['applies_to_products'] = array_values($productIds);
            }

            $result = Creem::profile($this->profile)->discounts()->create($payload);

            // Reload discounts from API to get authoritative list
            $this->loadDiscounts();

            session()->flash('success', "Discount code \"{$code}\" created!");
            $this->showForm = false;
            $this->showModal = false;
            $this->reset(['discountName', 'discountCode', 'selectedProducts']);
            $this->discountAmount   = 20;
            $this->discountType     = 'percentage';
            $this->discountDuration = 'once';
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        } finally {
            $this->loading = false;
        }
    }

    public function clearDiscounts(): void
    {
        $sessionId = session()->getId();
        cache()->forget("demo_discounts_{$this->profile}_{$sessionId}");
        $this->discounts = [];
    }

    public function openModal(): void
    {
        // Reset form and errors when opening
        $this->error = null;
        $this->discountName = '';
        $this->discountCode = '';
        $this->selectedProducts = [];
        $this->discountAmount = 20;
        $this->discountType = 'percentage';
        $this->discountDuration = 'once';
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
    }

    #[On('configuration-updated')]
    #[On('profile-switched')]
    public function refresh(): void
    {
        $this->checkConfig();
        $sessionId = session()->getId();
        $this->discounts = cache()->get("demo_discounts_{$this->profile}_{$sessionId}", []);
    }

    public function render()
    {
        return view('creemdemo::livewire.discount-demo');
    }
}
