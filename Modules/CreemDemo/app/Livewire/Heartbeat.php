<?php

namespace Modules\CreemDemo\Livewire;

use Livewire\Component;

class Heartbeat extends Component
{
    /** Refresh TTL for saved profiles (called via wire:poll) */
    public function keepAlive(): void
    {
        $profiles = session('creem_demo_config', []);
        foreach ($profiles as $name => $data) {
            $key = $data['cache_key'] ?? '';
            if ($key && !empty($data['api_key'])) {
                cache()->put(ConfigurationForm::CACHE_PREFIX . $key, [
                    'profile_name'   => $name,
                    'api_key'        => $data['api_key'],
                    'webhook_secret' => $data['webhook_secret'] ?? '',
                    'test_mode'      => $data['test_mode'] ?? true,
                ], ConfigurationForm::CACHE_TTL);
            }
        }
    }

    public function render()
    {
        return view('creemdemo::livewire.heartbeat');
    }
}
