<div wire:init="loadSubscriptions" style="display:flex;flex-direction:column;gap:14px;">
    {{-- Modal --}}
    @if($showCreateModal && !empty($draftPlan))
    <div style="position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:200;display:flex;align-items:center;justify-content:center;padding:20px;">
        <div class="card" style="width:100%;max-width:420px;border-radius:12px;" @click.stop>
            <div class="card-head">
                <div>
                    <div style="font-size:15px;font-weight:700;color:#111;">Preview Plan</div>
                    <div style="font-size:12px;color:#aaa;margin-top:2px;">Review before creating via API</div>
                </div>
                <button wire:click="cancelCreate" style="background:none;border:none;cursor:pointer;color:#bbb;">
                    <svg style="width:18px;height:18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div style="padding:18px;">
                <div style="background:#f5f0ff;border:1px solid #e8e0ff;border-radius:8px;padding:16px;margin-bottom:16px;">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;">
                        <div>
                            <div style="font-size:16px;font-weight:700;color:#111;">{{ $draftPlan['name'] }}</div>
                            <div style="font-size:12.5px;color:#888;margin-top:2px;min-height:36px;line-height:1.55;">{{ $draftPlan['description'] }}</div>
                        </div>
                        <span class="badge badge-purple" style="flex-shrink:0;margin-left:10px;">recurring</span>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;padding-top:10px;border-top:1px solid #e8e0ff;">
                        <div>
                            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#aaa;">Price</div>
                            <div style="font-size:14px;font-weight:700;color:#111;margin-top:2px;">${{ number_format($draftPlan['price']/100,2) }}</div>
                        </div>
                        <div>
                            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#aaa;">Period</div>
                            <div style="font-size:12px;font-weight:700;color:#111;margin-top:2px;">{{ \Modules\CreemDemo\Livewire\SubscriptionsList::BILLING_PERIODS[$draftPlan['billing_period']] }}</div>
                        </div>
                        <div>
                            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#aaa;">API key</div>
                            <div style="font-size:11px;font-family:monospace;color:#7c3aed;margin-top:2px;">{{ $draftPlan['billing_period'] }}</div>
                        </div>
                    </div>
                </div>
                <div style="display:flex;gap:8px;">
                    <button wire:click="cancelCreate" class="btn btn-ghost" style="flex:1;justify-content:center;">Cancel</button>
                    <button wire:click="regenerateDraft" class="btn btn-ghost" title="Regenerate">
                        <svg style="width:15px;height:15px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </button>
                    <button wire:click="confirmCreate" wire:loading.attr="disabled" class="btn btn-violet" style="flex:1;justify-content:center;">
                        <span wire:loading.remove wire:target="confirmCreate">Create Plan →</span>
                        <span wire:loading wire:target="confirmCreate">Creating…</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Plans --}}
    <div class="card">
        <div class="card-head">
            <div>
                <div style="font-size:14px;font-weight:600;color:#111;">Recurring Plans</div>
                <div style="font-size:12px;color:#aaa;margin-top:2px;">monthly · quarterly · bi-annual · annual</div>
            </div>
            @if($isConfigured)
            <button wire:click="prepareCreate" wire:loading.attr="disabled" class="btn btn-violet btn-sm">
                <span wire:loading.remove wire:target="prepareCreate">+ New Plan</span>
                <span wire:loading wire:target="prepareCreate">Generating…</span>
            </button>
            @endif
        </div>

        @if(!$isConfigured)
        <div style="padding:40px;text-align:center;color:#bbb;"><div style="font-size:36px;margin-bottom:10px;">🔑</div><div style="font-size:14px;">Configure API credentials in Setup.</div></div>
        @elseif($loading)
        <div style="padding:36px;text-align:center;"><svg class="animate-spin" style="width:24px;height:24px;color:#7c3aed;margin:0 auto 8px;" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg></div>
        @elseif($error)
        <div style="margin:14px;padding:12px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;font-size:13px;color:#b91c1c;">{{ $error }}</div>
        @elseif(empty($products))
        <div style="padding:40px;text-align:center;color:#bbb;"><div style="font-size:36px;margin-bottom:10px;">🔄</div><div style="font-size:14px;">No plans yet. Click <strong style="color:#555;">+ New Plan</strong>.</div></div>
        @else
        <div>
            @foreach($products as $plan)
            <div style="display:flex;align-items:center;gap:14px;padding:13px 18px;border-bottom:1px solid #f5f5f7;"
                 onmouseover="this.style.background='#fafafa'" onmouseout="this.style.background=''">
                <div style="flex:1;min-width:0;">
                    <div style="font-size:14px;font-weight:600;color:#111;">{{ $plan['name'] }}</div>
                    <div style="font-size:12px;color:#aaa;margin-top:2px;display:flex;align-items:center;gap:8px;">
                        <span style="font-family:monospace;">{{ strtoupper($plan['currency'] ?? 'USD') }} {{ number_format(($plan['price'] ?? 0)/100,2) }}</span>
                        <span class="badge badge-purple" style="font-size:10px;">{{ $plan['billing_period'] ?? 'every-month' }}</span>
                    </div>
                </div>
                <button wire:click="subscribe('{{ $plan['id'] }}')" class="btn btn-violet btn-sm" style="flex-shrink:0;">
                    Subscribe →
                </button>
            </div>
            @endforeach
        </div>
        @endif
    </div>

    {{-- Active subscriptions --}}
    @if(!empty($activeSubscriptions))
    <div class="card">
        <div class="card-head">
            <div style="font-size:14px;font-weight:600;color:#111;">Session Subscriptions</div>
            <span style="font-size:12px;color:#aaa;">from webhook events</span>
        </div>
        <div>
            @foreach($activeSubscriptions as $i => $sub)
            @php $badges=['active'=>'badge-green','paused'=>'badge-amber','cancelled'=>'badge-red','expired'=>'badge-gray']; $b=$badges[$sub['status']??'active']??'badge-gray'; @endphp
            <div style="display:flex;align-items:center;gap:12px;padding:11px 18px;border-bottom:1px solid #f5f5f7;"
                 onmouseover="this.style.background='#fafafa'" onmouseout="this.style.background=''">
                <div style="flex:1;min-width:0;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span style="font-size:13.5px;font-weight:600;color:#111;overflow:hidden;text-overflow:ellipsis;">{{ $sub['email'] ?? '?' }}</span>
                        <span class="badge {{ $b }}">{{ strtoupper($sub['status'] ?? 'active') }}</span>
                    </div>
                    <div style="font-size:12px;color:#aaa;margin-top:2px;">{{ $sub['product_name'] ?? '' }}</div>
                </div>
                <div style="display:flex;gap:6px;flex-shrink:0;">
                    @if(($sub['status']??'')==='active') <button wire:click="pauseSubscription({{ $i }})" class="btn btn-ghost btn-sm">Pause</button> @endif
                    @if(($sub['status']??'')==='paused') <button wire:click="resumeSubscription({{ $i }})" class="btn btn-ghost btn-sm">Resume</button> @endif
                    @if(!in_array($sub['status']??'',['cancelled','expired'])) <button wire:click="cancelSubscription({{ $i }})" class="btn btn-danger btn-sm">Cancel</button> @endif
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif
</div>
