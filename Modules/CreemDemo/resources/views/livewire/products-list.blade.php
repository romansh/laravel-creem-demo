<div wire:init="loadProducts">
    @if (session()->has('error'))
        <div style="margin:14px;padding:12px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;font-size:13px;color:#b91c1c;">
            ⚠ {{ session('error') }}
        </div>
    @endif
    {{-- Create modal --}}
    @if($showCreateModal && !empty($draftProduct))
    <div style="position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:200;display:flex;align-items:center;justify-content:center;padding:20px;">
        <div class="card" style="width:100%;max-width:420px;border-radius:12px;" @click.stop>
            <div class="card-head">
                <div>
                    <div style="font-size:15px;font-weight:700;color:#111;">Preview Product</div>
                    <div style="font-size:12px;color:#aaa;margin-top:2px;">Review before creating via API</div>
                </div>
                <button wire:click="cancelCreate" style="background:none;border:none;cursor:pointer;color:#bbb;padding:4px;">
                    <svg style="width:18px;height:18px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div style="padding:18px;">
                <div style="background:#f0f0ff;border:1px solid #e0e0ff;border-radius:8px;padding:16px;margin-bottom:16px;">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;">
                        <div>
                            <div style="font-size:16px;font-weight:700;color:#111;">{{ $draftProduct['name'] }}</div>
                            <div style="font-size:12.5px;color:#888;margin-top:2px;min-height:36px;line-height:1.55;">{{ $draftProduct['description'] }}</div>
                        </div>
                        <span class="badge badge-green" style="flex-shrink:0;margin-left:10px;">onetime</span>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;padding-top:10px;border-top:1px solid #e0e0ff;">
                        @foreach([['Price','$'.number_format($draftProduct['price']/100,2)],['Currency',$draftProduct['currency']],['Tax',$draftProduct['tax_mode']]] as [$l,$v])
                        <div>
                            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#aaa;">{{ $l }}</div>
                            <div style="font-size:14px;font-weight:700;color:#111;margin-top:2px;">{{ $v }}</div>
                        </div>
                        @endforeach
                    </div>
                </div>
                <div style="display:flex;gap:8px;">
                    <button wire:click="cancelCreate" class="btn btn-ghost" style="flex:1;justify-content:center;">Cancel</button>
                    <button wire:click="regenerateDraft" class="btn btn-ghost" title="Regenerate">
                        <svg style="width:15px;height:15px;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </button>
                    <button wire:click="confirmCreate" wire:loading.attr="disabled" class="btn btn-indigo" style="flex:1;justify-content:center;">
                        <span wire:loading.remove wire:target="confirmCreate">Create →</span>
                        <span wire:loading wire:target="confirmCreate">Creating…</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    <div class="card">
        <div class="card-head">
            <div>
                <div style="font-size:14px;font-weight:600;color:#111;">One-Time Products</div>
                <div style="font-size:12px;color:#aaa;margin-top:2px;">billing_type = onetime</div>
            </div>
            @if($isConfigured)
            <button wire:click="prepareCreate" wire:loading.attr="disabled" class="btn btn-indigo btn-sm">
                <span wire:loading.remove wire:target="prepareCreate">+ New Product</span>
                <span wire:loading wire:target="prepareCreate">Generating…</span>
            </button>
            @endif
        </div>

        @if(!$isConfigured)
        <div style="padding:40px;text-align:center;color:#bbb;">
            <div style="font-size:36px;margin-bottom:10px;">🔑</div>
            <div style="font-size:14px;font-weight:500;">Configure API credentials in Setup first.</div>
        </div>
        @elseif($loading)
        <div style="padding:40px;text-align:center;">
            <svg class="animate-spin" style="width:24px;height:24px;color:#6366f1;margin:0 auto 8px;" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
            <div style="font-size:13px;color:#aaa;">Loading products…</div>
        </div>
        @elseif($error)
        <div style="margin:14px;padding:12px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;font-size:13px;color:#b91c1c;">⚠ {{ $error }}</div>
        @elseif(empty($products))
        <div style="padding:40px;text-align:center;color:#bbb;">
            <div style="font-size:36px;margin-bottom:10px;">📦</div>
            <div style="font-size:14px;font-weight:500;">No one-time products yet.</div>
            <div style="font-size:12.5px;margin-top:4px;">Click <strong style="color:#555;">+ New Product</strong> to create one.</div>
        </div>
        @else
        <div>
            @foreach($products as $product)
            <div style="display:flex;align-items:center;gap:14px;padding:13px 18px;border-bottom:1px solid #f5f5f7;"
                 onmouseover="this.style.background='#fafafa'" onmouseout="this.style.background=''">
                <div style="flex:1;min-width:0;">
                    <div style="font-size:14px;font-weight:600;color:#111;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $product['name'] }}</div>
                    <div style="font-size:12px;color:#aaa;margin-top:2px;display:flex;align-items:center;gap:8px;">
                        <span style="font-family:monospace;">{{ strtoupper($product['currency'] ?? 'USD') }} {{ number_format(($product['price'] ?? 0)/100,2) }}</span>
                        <span style="color:#e2e2e6;">·</span>
                        <code style="font-size:10.5px;color:#ccc;">{{ $product['id'] }}</code>
                    </div>
                </div>
                <button wire:click="buyProduct('{{ $product['id'] }}')"
                    class="btn btn-green btn-sm" style="flex-shrink:0;">
                    Buy →
                </button>
            </div>
            @endforeach
        </div>
        @endif
    </div>
</div>
