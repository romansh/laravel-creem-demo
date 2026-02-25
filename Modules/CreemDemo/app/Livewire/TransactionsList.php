<?php

namespace Modules\CreemDemo\Livewire;

use Livewire\Component;
use Livewire\Attributes\On;
use Romansh\LaravelCreem\Facades\Creem;
use Carbon\Carbon;

class TransactionsList extends Component
{
    public string $profile = 'default';
    public array $transactions = [];
    public array $productMap = [];
    public array $customerMap = [];
    public bool $loading = true;
    public int $page = 1;

    protected function checkAndApplyConfig(): void
    {
        ConfigurationForm::applyCacheConfig();
        // ensure profile uses runtime config key if present
        $this->profile = cache()->get(ConfigurationForm::getCacheActiveProfileKey(), $this->profile ?? 'default');
    }

    public function mount(string $profile = 'default'): void
    {
        $this->profile = $profile;
        // Defer API call to wire:init="loadTransactions"
    }

    public function loadTransactions(): void
    {
        $this->loading = true;
        $this->checkAndApplyConfig();
        $this->productMap = [];

        try {
            $response = Creem::profile($this->profile)->transactions()->list([], $this->page, 20);
            $this->transactions = $response['items'] ?? [];

            // NOTE: product names are not fetched from the API here — use data included in the
            // transaction payload (if present). Avoid server-side product lookups to keep
            // the Transactions list performant and compatible with providers that don't
            // expose product resources via transaction endpoints.
            $this->productMap = [];

            // Load customer labels for visible transactions
            $this->customerMap = [];
            $customerIds = collect($this->transactions)
                ->map(function ($t) {
                    $c = data_get($t, 'customer');
                    if (is_array($c)) {
                        return $c['id'] ?? null;
                    }
                    return $c;
                })
                ->filter()
                ->unique()
                ->values()
                ->all();

            foreach ($customerIds as $cid) {
                try {
                    $c = Creem::profile($this->profile)->customers()->find($cid);
                    $this->customerMap[$cid] = $c['email'] ?? $c['name'] ?? $c['id'] ?? $cid;
                } catch (\Exception $e) {
                    $this->customerMap[$cid] = $cid;
                }
            }
        } catch (\Exception $e) {
            $this->transactions = [];
        } finally {
            $this->loading = false;
        }
    }

    public function nextPage(): void
    {
        $this->page++;
        $this->loadTransactions();
    }

    public function prevPage(): void
    {
        if ($this->page > 1) $this->page--;
        $this->loadTransactions();
    }

    #[On('configuration-updated')]
    public function refreshFromConfig(): void
    {
        $this->loadTransactions();
    }

    #[On('profile-switched')]
    public function switchProfile(string $name = ''): void
    {
        if ($name !== '') {
            $this->profile = $name;
        }
        $this->page = 1;
        $this->loadTransactions();
    }

    public function render()
    {
        return view('creemdemo::livewire.transactions-list');
    }
}
