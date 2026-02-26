<div wire:init="loadProducts" class="card">
    <div class="card-head" style="flex-direction:column;align-items:stretch;gap:0;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;">
            <div>
                <div style="font-size:14px;font-weight:600;color:#111;">Discount Codes</div>
                <div style="font-size:12px;color:#aaa;margin-top:2px;">Create percentage or fixed coupons via API</div>
            </div>
            @if($isConfigured)
            <div style="display:flex;gap:8px;align-items:center;">
                @if(count($discounts) > 0)
                    <button wire:click="clearDiscounts" style="background:none;border:none;cursor:pointer;font-size:12px;color:#ccc;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#ccc'">Clear</button>
                @endif
                <button type="button" wire:click="openModal" class="btn btn-indigo btn-sm">
                    + New Code
                </button>
            </div>
            @endif
        </div>
        <div style="font-size:12px;color:#6b7280;margin-top:8px;">Only discounts created in this session are shown (Creem API doesn't provide a list). Previously created discounts can still be applied at checkout.</div>
    </div>

    @if(!$isConfigured)
        <div style="padding:40px;text-align:center;color:#bbb;"><div style="font-size:36px;margin-bottom:10px;">🏷️</div><div style="font-size:14px;">Configure API credentials in Setup first.</div></div>
    @else
        {{-- Create discount modal (Livewire-only fallback) --}}
        @if($showModal)
            <div wire:click="closeModal" style="position:fixed;inset:0;background:rgba(0,0,0,.3);z-index:99;display:flex;align-items:center;justify-content:center;">
                <div wire:click.stop style="position:relative;z-index:100;background:#fff;border:1px solid #e8e8ec;border-radius:10px;width:92%;max-width:700px;max-height:calc(100vh - 80px);overflow:hidden;box-shadow:0 20px 25px -5px rgba(0,0,0,.1),0 10px 10px -5px rgba(0,0,0,.04);display:flex;flex-direction:column;">
                    {{-- Modal header --}}
                    <div style="padding:18px 20px;border-bottom:1px solid #f0f0f2;flex-shrink:0;display:flex;align-items:center;justify-content:space-between;">
                        <h3 style="font-size:15px;font-weight:700;color:#111;margin:0;">Create Discount Code</h3>
                        <button wire:click="closeModal" style="background:none;border:none;cursor:pointer;font-size:20px;color:#ccc;padding:0;width:24px;height:24px;display:flex;align-items:center;justify-content:center;" type="button">×</button>
                    </div>
                    {{-- Modal content (scrollable) --}}
                    <div style="padding:20px;overflow:auto;flex:1 1 auto;">
                        @if($error)
                        <div style="background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:10px 14px;border-radius:7px;font-size:13px;margin-bottom:16px;">{{ $error }}</div>
                        @endif
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
                            <div><label class="label">Name</label><input wire:model.defer="discountName" type="text" placeholder="Launch Special" class="input" style="font-size:13px;"></div>
                            <div><label class="label">Code</label><input wire:model.defer="discountCode" type="text" placeholder="LAUNCH30" class="input" style="font-size:13px;text-transform:uppercase;font-family:monospace;"></div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr;gap:12px;margin-bottom:14px;">
                            <div>
                                <label class="label">Apply to product(s)</label>
                                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px;padding:10px;background:#f9f9fb;border:1px solid #e8e8ec;border-radius:7px;max-height:220px;overflow-y:auto;">
                                    @if(empty($products))
                                        <div style="color:#aaa;font-size:13px;padding:20px 0;text-align:center;grid-column:1/-1;">No products available</div>
                                    @else
                                        @foreach($products as $p)
                                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:6px;border-radius:4px;user-select:none;" onmouseover="this.style.background='#f0f0f2'" onmouseout="this.style.background='transparent'">
                                                <input type="checkbox" wire:model.live="selectedProducts" value="{{ $p['id'] }}" style="width:16px;height:16px;cursor:pointer;flex-shrink:0;">
                                                <span style="font-size:13px;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $p['name'] ?? '?' }} <span style="color:#aaa;font-size:11px;">({{ $p['billing_type'] ?? '' }})</span></span>
                                            </label>
                                        @endforeach
                                    @endif
                                </div>
                                @if(empty($selectedProducts))
                                    <div style="font-size:11px;color:#ef4444;margin-top:4px;">⚠ At least one product is required</div>
                                @else
                                    <div style="font-size:11px;color:#22c55e;margin-top:4px;">✓ {{ count($selectedProducts) }} product(s) selected</div>
                                @endif
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:16px;">
                            <div>
                                <label class="label">Type</label>
                                <select wire:model.live="discountType" class="input" style="font-size:13px;">
                                    <option value="percentage">Percentage (%)</option>
                                    <option value="fixed">Fixed (cents)</option>
                                </select>
                            </div>
                            <div>
                                <label class="label">{{ $discountType==='percentage' ? 'Percent off' : 'Amount (cents)' }}</label>
                                <input wire:model.defer="discountAmount" type="number" class="input" style="font-size:13px;">
                            </div>
                            <div>
                                <label class="label">Duration</label>
                                <select wire:model.defer="discountDuration" class="input" style="font-size:13px;">
                                    <option value="once">Once (first payment)</option>
                                    <option value="forever">Forever</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    {{-- Modal footer --}}
                    <div style="padding:14px 20px;border-top:1px solid #f0f0f2;flex-shrink:0;display:flex;align-items:center;justify-content:space-between;gap:10px;">
                        <button type="button" wire:click="closeModal" class="btn btn-ghost btn-sm" style="margin:0;">Cancel</button>
                        <button type="button" wire:click="createDiscount" wire:loading.attr="disabled" class="btn btn-indigo" style="margin:0;">
                            <span wire:loading.remove wire:target="createDiscount">Create Code</span>
                            <span wire:loading wire:target="createDiscount">Creating…</span>
                        </button>
                    </div>
                </div>
            </div>
        @endif

        @if(empty($discounts))
        <div style="padding:40px;text-align:center;color:#bbb;"><div style="font-size:36px;margin-bottom:10px;">🎫</div><div style="font-size:14px;">No codes yet. Click <strong style="color:#555;">+ New Code</strong>.</div></div>
        @else
        <div>
            @foreach($discounts as $discount)
            <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 18px;border-bottom:1px solid #f5f5f7;">
                <div>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <code style="font-size:15px;font-weight:700;color:#4f46e5;font-family:monospace;">{{ $discount['code'] ?? '?' }}</code>
                        <span class="badge badge-green">{{ strtoupper($discount['status'] ?? 'ACTIVE') }}</span>
                        <span class="badge badge-gray" style="font-size:10px;">{{ $discount['duration'] ?? '' }}</span>
                    </div>
                        <div style="font-size:12px;color:#aaa;margin-top:3px;">
                        {{ $discount['name'] ?? '' }} ·
                        @if(($discount['type']??'')==='percentage') <strong>{{ $discount['amount']??0 }}%</strong> off
                        @else <strong>${{ number_format(($discount['amount']??0)/100,2) }}</strong> off
                        @endif
                        @if(!empty($discount['product_ids']))
                            @php
                                $map = $productMap ?? [];
                                $names = array_map(function($id) use ($map) {
                                    return $map[$id] ?? $id;
                                }, $discount['product_ids']);
                            @endphp
                            • <span style="color:#6b7280;">Products: {{ implode(', ', $names) }}</span>
                        @elseif(!empty($discount['product_id']))
                            @php $map = $productMap ?? []; @endphp
                            <span style="color:#6b7280;">Product: {{ $map[$discount['product_id']] ?? $discount['product_id'] }}</span>
                        @endif
                    </div>
                </div>
            </div>
            @endforeach
        </div>
        @endif
    @endif
</div>
