<?php

namespace Modules\CreemDemo\Livewire;

use Romansh\LaravelCreem\Facades\Creem;
use Livewire\Component;
use Livewire\Attributes\On;
use Faker\Factory as Faker;

class ProductsList extends Component
{
    public string  $profile      = 'default';
    public array   $products     = [];
    public bool    $loading      = true;
    public ?string $error        = null;
    public bool    $isConfigured = false;

    // Create preview popup
    public bool   $showCreateModal = false;
    public array  $draftProduct    = [];

    protected function checkAndApplyConfig(): void
    {
        $this->profile = session('creem_demo_active_profile', 'default');
        // Apply session config into runtime so Creem::profile(...) will work
        ConfigurationForm::applySessionConfig();
        $config = session('creem_demo_config', []);
        $this->isConfigured = !empty($config[$this->profile]['api_key']);
    }

    public function mount(): void
    {
        $this->checkAndApplyConfig();
        // Defer API call to wire:init="loadProducts"
    }

    public function loadProducts(): void
    {
        $this->loading = true;
        $this->error   = null;
        $this->checkAndApplyConfig();

        if (!$this->isConfigured) { $this->products = []; $this->loading = false; return; }

        try {
            $response       = Creem::profile($this->profile)->products()->list();
            $this->products = array_values(array_filter(
                $response['items'] ?? [],
                fn($p) => ($p['billing_type'] ?? '') === 'onetime'
            ));
        } catch (\Throwable $e) {
            $this->error    = $e->getMessage();
            $this->products = [];
        } finally {
            $this->loading = false;
        }
    }

    /** Generate random product draft and show preview modal */
    public function prepareCreate(): void
    {
        $faker = Faker::create();
        $adjectives = ['Premium', 'Pro', 'Ultimate', 'Essential', 'Advanced', 'Classic', 'Elite', 'Studio'];
        $nouns      = ['Toolkit', 'Plugin', 'Bundle', 'Pack', 'Suite', 'Module', 'Asset', 'Template'];

        $this->draftProduct = [
            'name'         => $faker->randomElement($adjectives) . ' ' . $faker->randomElement($nouns) . ' ' . rand(1, 9),
            'description'  => $faker->sentence(14),
            'price'        => rand(5, 99) * 100,
            'currency'     => 'USD',
            'billing_type' => 'onetime',
            'tax_mode'     => 'inclusive',
            'tax_category' => 'saas',
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
            Creem::profile($this->profile)->products()->create($this->draftProduct);
            session()->flash('success', "Product \"{$this->draftProduct['name']}\" created!");
            $this->showCreateModal = false;
            $this->loadProducts();
            $this->dispatch('configuration-updated');
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function cancelCreate(): void
    {
        $this->showCreateModal = false;
        $this->draftProduct    = [];
    }

    public function buyProduct(string $productId): void
    {
        $this->checkAndApplyConfig();
        try {
            $faker    = Faker::create();
            $checkout = Creem::profile($this->profile)->checkouts()->create([
                'product_id'  => $productId,
                'customer'    => ['email' => $faker->safeEmail()],
                'success_url' => route('creem-demo.success'),
                'metadata'    => ['demo' => true],
            ]);
            $url = $checkout['checkout_url'] ?? null;
            if (!$url) {
                session()->flash('error', 'Checkout URL not returned — check API key or product status.');
                return;
            }
            $this->redirect($url);
        } catch (\Throwable $e) {
            session()->flash('error',$e->getMessage());
        }
    }

    #[On('configuration-updated')]
    #[On('profile-switched')]
    public function refreshFromConfig(): void
    {
        $this->checkAndApplyConfig();
        $this->loadProducts();
    }

    public function render()
    {
        return view('creemdemo::livewire.products-list');
    }
}
