<div style="display:grid;grid-template-columns:minmax(480px,1fr) minmax(300px,420px);gap:20px;align-items:start;">

<div>

    @if(session()->has('success'))
    <div x-data="{v:true}" x-init="setTimeout(()=>v=false,3500)" x-show="v" x-transition
         style="display:flex;align-items:center;gap:8px;background:#f0fdf4;border:1px solid #bbf7d0;color:#16a34a;padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:16px;">
        ✓ {{ session('success') }}
    </div>
    @endif
    @if(session()->has('error'))
    <div style="background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:16px;">
        ⚠ {{ session('error') }}
    </div>
    @endif

    {{-- === PROFILES TABS === --}}
    <div class="card" style="margin-bottom:16px;"
         x-data="{ statsLoading: true, statsUnconfigured: false, switchTarget: '' }"
         @profile-switched.window="statsLoading=true; statsUnconfigured=false; switchTarget=$event.detail.name||''"
         @configuration-updated.window="statsLoading=true; statsUnconfigured=false"
         @stats-loaded.window="statsLoading=false; statsUnconfigured=!$event.detail.configured; switchTarget=''">
        <div style="padding:0 16px;border-bottom:1px solid #e8e8ec;display:flex;align-items:center;gap:4px;overflow-x:auto;background:#fafafa;flex-wrap:nowrap;">
            @foreach($profiles as $name => $data)
            <button wire:click="switchProfile('{{ $name }}')"
                style="padding:10px 16px;font-size:13.5px;font-weight:600;border:none;cursor:pointer;white-space:nowrap;
                       border-bottom:2px solid {{ $activeProfile === $name ? '#4f46e5' : 'transparent' }};
                       background:{{ $activeProfile === $name ? '#fff' : 'transparent' }};
                       color:{{ $activeProfile === $name ? '#4f46e5' : '#777' }};
                       display:inline-flex;align-items:center;gap:7px;transition:all .12s;border-radius:6px 6px 0 0;
                       {{ $activeProfile === $name ? 'box-shadow:0 -2px 0 0 #4f46e5 inset,0 1px 0 #fff inset;' : '' }}
                       margin-bottom:-1px;">
                {{-- Lamp: orange blink while loading target, solid orange if unconfigured, green if ok, gray otherwise --}}
                <span style="width:7px;height:7px;border-radius:50%;flex-shrink:0;display:inline-block;"
                      :class="{ 'blink': statsLoading && (switchTarget==='{{ $name }}' || (!switchTarget && '{{ $activeProfile }}'==='{{ $name }}')) }"
                      :style="{ background:
                          (statsLoading && (switchTarget==='{{ $name }}' || (!switchTarget && '{{ $activeProfile }}'==='{{ $name }}')))
                              ? '#f59e0b'
                              : (!statsLoading && statsUnconfigured && '{{ $activeProfile }}'==='{{ $name }}')
                                  ? '#f59e0b'
                                  : ('{{ $activeProfile }}'==='{{ $name }}' && {{ !empty($data['api_key']) ? 'true' : 'false' }})
                                      ? '#22c55e'
                                      : '#d1d5db'
                      }"></span>
                {{-- Spinner while switching --}}
                <svg wire:loading.inline wire:target="switchProfile" style="width:12px;height:12px;color:#6366f1;display:none;margin-left:2px;animation:spin 1s linear infinite;" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" opacity=".25"/><path fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" opacity=".75"/></svg>
                {{ $name }}
                @if($name !== 'default')
                <span wire:click.stop="removeProfile('{{ $name }}')"
                      style="font-size:11px;color:#ccc;cursor:pointer;margin-left:2px;"
                      onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#ccc'">✕</span>
                @endif
            </button>
            @endforeach

            {{-- Add profile --}}
            <div x-data="{open:false, name:@entangle('newProfileName')}" style="display:inline-flex;align-items:center;margin-left:4px;">
                <button @click="open=!open; $nextTick(() => $refs.profileInput.focus())"
                    style="padding:7px 12px;font-size:12.5px;font-weight:600;border:1px dashed #c7d2fe;border-radius:6px;background:#f0f4ff;cursor:pointer;color:#6366f1;white-space:nowrap;display:flex;align-items:center;gap:5px;transition:all .12s;"
                    onmouseover="this.style.background='#e0e7ff'" onmouseout="this.style.background='#f0f4ff'">
                    <svg style="width:13px;height:13px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                    New Profile
                </button>
                <div x-show="open" x-cloak x-transition @click.outside="open=false"
                     style="position:absolute;background:#fff;border:1px solid #e2e2e6;border-radius:8px;padding:12px;width:220px;box-shadow:0 8px 24px rgba(0,0,0,.1);z-index:99;margin-top:40px;">
                    <div class="label" style="margin-bottom:6px;">Profile name</div>
                    <div style="display:flex;gap:6px;">
                        <input x-ref="profileInput" x-model="name" type="text" placeholder="staging"
                               class="input" style="flex:1;font-size:13px;padding:6px 10px;" @keydown.enter="$wire.addProfile(); open=false;">
                        <button wire:click="addProfile" @click="open=false"
                                class="btn btn-indigo btn-sm">Add</button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Credential fields --}}
        {{-- Webhook URL: shown after profile is saved (moved above credentials for visibility) --}}
        @if(!empty($profiles[$activeProfile]['webhook_url']))
        <div style="padding:12px 18px;border-bottom:1px solid #eef2ff;background:#f8fafc;">
            <div style="font-size:11px;font-weight:700;color:#6366f1;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;">
                📡 Webhook URL for <strong>{{ $activeProfile }}</strong>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                <input type="text" readonly
                       value="{{ $profiles[$activeProfile]['webhook_url'] }}"
                       onclick="this.select()"
                       style="flex:1;font-family:monospace;font-size:12px;padding:7px 10px;border:1px solid #e6eefc;border-radius:6px;background:#fff;color:#3730a3;cursor:pointer;">
                <button onclick="navigator.clipboard.writeText('{{ $profiles[$activeProfile]['webhook_url'] }}');this.textContent='✓';setTimeout(()=>this.textContent='Copy',1500)"
                        style="padding:7px 12px;font-size:12px;font-weight:600;border:1px solid #c7d2fe;border-radius:6px;background:#eff0fb;color:#4f46e5;cursor:pointer;white-space:nowrap;">
                    Copy
                </button>
            </div>
            <div style="font-size:10.5px;color:#6b7280;margin-top:5px;">
                Paste this URL in your Creem dashboard → Webhooks. Cache stays active for 2 hours; heartbeat refreshes every 1 hour 59 minutes (only when browser tab is active).
            </div>
        </div>
        @endif

        <div style="padding:18px;display:grid;grid-template-columns:1fr;gap:14px;">
            <div>
                <label class="label">API Key</label>
                <input type="text" wire:model.live="apiKey" placeholder="creem_test_..."
                       class="input" style="font-family:monospace;font-size:13px;">
                <div style="font-size:11px;color:#bbb;margin-top:4px;"><code>creem_test_</code> = sandbox &nbsp;·&nbsp; <code>creem_</code> = live</div>
            </div>
            <div>
                <label class="label">Webhook Secret</label>
                <input type="text" wire:model.live="webhookSecret" placeholder="whsec_..."
                       class="input" style="font-family:monospace;font-size:13px;">
                <div style="font-size:11px;color:#bbb;margin-top:4px;">From Creem Dashboard → Developers → Webhooks. The webhook URL is generated below after saving.</div>
            </div>
        </div>
        <div style="padding:12px 18px;background:#fffbeb;border-top:1px solid #fde68a;display:flex;align-items:center;gap:8px;font-size:12px;color:#92400e;">
            <svg style="width:14px;height:14px;flex-shrink:0;" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
            <span>Demo always uses <strong>test_mode: true</strong>. Do not use production keys — use only test keys starting with <code style="background:#fde68a;padding:1px 4px;border-radius:3px;">creem_test_</code></span>
        </div>
        <div style="padding:12px 18px;border-top:1px solid #f0f0f2;display:flex;align-items:center;justify-content:space-between;">
            <button wire:click="clearCache" class="btn btn-ghost btn-sm" style="color:#aaa;border-color:transparent;">
                🗑 Clear cache
            </button>
            <button wire:click="saveConfiguration" wire:loading.attr="disabled" class="btn btn-indigo">
                <span wire:loading.remove>Save & Connect</span>
                <span wire:loading>Saving…</span>
            </button>
        </div>

    </div>

    </div>

    <div class="code-wrap">
        <div class="code-topbar">
            <span style="font-size:11px;font-weight:600;color:#cbd5e1;text-transform:uppercase;letter-spacing:.06em;">Multi-profile Example</span>
            <button onclick="copyCode(this)" style="font-size:12px;color:#888;background:none;border:none;cursor:pointer;padding:2px 8px;border-radius:4px;">Copy</button>
        </div>
        <pre><code>// Configure profiles in config/creem.php:
'profiles' => [
    'default' => [
        'api_key' => env('CREEM_API_KEY'),
        'test_mode' => env('CREEM_TEST_MODE', false),
        'webhook_secret' => env('CREEM_WEBHOOK_SECRET'),
    ],
    'product_a' => [
        'api_key' => env('CREEM_PRODUCT_A_KEY'),
        'test_mode' => true,
        'webhook_secret' => env('CREEM_PRODUCT_A_SECRET'),
    ],
],

// Use default profile (implicit):
$products = Creem::products()->list();

// Use named profile explicitly:
$checkout = Creem::profile('product_a')
    ->checkouts()
    ->create([...]);

// Or with inline config (no profile needed):
$result = Creem::withConfig([
    'api_key' => 'creem_test_xyz',
    'test_mode' => true,
])->products()->find('prod_123');
</code></pre>
    </div>

</div>
