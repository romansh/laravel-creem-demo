<div style="display:flex;flex-direction:column;gap:14px;">
    @if(!$isConfigured)
    <div style="background:#fffbeb;border:1px solid #fde68a;color:#92400e;padding:12px 16px;border-radius:8px;font-size:13px;display:flex;align-items:center;gap:8px;">
        <svg style="width:16px;height:16px;flex-shrink:0;" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
        Configure API credentials in Setup first.
    </div>
    @endif

    {{-- Captured from webhook --}}
    <div class="card">
        <div class="card-head">
            <div>
                <div style="font-size:14px;font-weight:600;color:#111;">Keys from Checkout</div>
                <div style="font-size:12px;color:#aaa;margin-top:2px;">Auto-captured from <code style="background:#f3f4f6;padding:1px 5px;border-radius:3px;font-size:11.5px;">checkout.completed</code></div>
            </div>
            @if(count($capturedLicenses) > 0)
            <button wire:click="clearHistory" style="background:none;border:none;cursor:pointer;font-size:12px;color:#ccc;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#ccc'">Clear</button>
            @endif
        </div>

        @if(empty($capturedLicenses))
        <div style="padding:36px;text-align:center;color:#bbb;"><div style="font-size:32px;margin-bottom:10px;">🔑</div><div style="font-size:14px;">No keys yet.</div><div style="font-size:12px;margin-top:4px;">Complete a test checkout — key arrives in webhook payload.</div></div>
        @else
        <div>
            @foreach($capturedLicenses as $i => $lic)
            @php $bs=['not_activated'=>'badge-gray','activated'=>'badge-green','deactivated'=>'badge-red']; $b=$bs[$lic['status']??'not_activated']??'badge-gray'; @endphp
            <div style="padding:14px 18px;border-bottom:1px solid #f5f5f7;">
                <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:10px;">
                    <div style="flex:1;min-width:0;">
                        <code style="font-size:13px;font-weight:700;color:#4f46e5;font-family:monospace;word-break:break-all;">{{ $lic['key'] }}</code>
                        <div style="font-size:12px;color:#aaa;margin-top:3px;">{{ $lic['email'] }} · {{ $lic['product_name'] }}</div>
                        @if($lic['instance_id']??null)
                        <code style="font-size:11px;color:#7c3aed;background:#f5f0ff;padding:2px 8px;border-radius:4px;display:inline-block;margin-top:4px;font-family:monospace;">instance: {{ $lic['instance_id'] }}</code>
                        @endif
                    </div>
                    <span class="badge {{ $b }}" style="flex-shrink:0;">{{ strtoupper(str_replace('_',' ',$lic['status']??'not activated')) }}</span>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:6px;">
                    @if(($lic['status']??'')!=='activated')
                    <button wire:click="activateCaptured({{ $i }})" wire:loading.attr="disabled" class="btn btn-indigo btn-sm">
                        <span wire:loading.remove wire:target="activateCaptured({{ $i }})">⚡ Activate</span>
                        <span wire:loading wire:target="activateCaptured({{ $i }})">…</span>
                    </button>
                    @endif
                    <button wire:click="validateCaptured({{ $i }})" wire:loading.attr="disabled" class="btn btn-ghost btn-sm">
                        <span wire:loading.remove wire:target="validateCaptured({{ $i }})">✓ Validate</span>
                        <span wire:loading wire:target="validateCaptured({{ $i }})">…</span>
                    </button>
                    @if(($lic['status']??'')==='activated')
                    <button wire:click="deactivateCaptured({{ $i }})" wire:loading.attr="disabled" class="btn btn-danger btn-sm">
                        <span wire:loading.remove wire:target="deactivateCaptured({{ $i }})">Deactivate</span>
                        <span wire:loading wire:target="deactivateCaptured({{ $i }})">…</span>
                    </button>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
        @endif
    </div>

    {{-- API result --}}
    @if($error)
    <div style="background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:12px 16px;border-radius:8px;font-size:13px;">⚠ {{ $error }}</div>
    @endif

    @if($actionResult)
    <div class="card" style="border-color:#c7d2fe;" class="fade-up">
        <div class="card-head" style="background:#f5f5ff;">
            <span style="font-size:13.5px;font-weight:600;color:#4338ca;">{{ ucfirst($actionResult['action']) }} Response</span>
            @if(isset($actionResult['valid']))
            <span class="badge {{ $actionResult['valid'] ? 'badge-green' : 'badge-red' }}">{{ $actionResult['valid'] ? '✓ VALID' : '✗ INVALID' }}</span>
            @endif
        </div>
        <div class="code-wrap" style="border-radius:0 0 10px 10px;">
            <pre style="font-size:11.5px;">{{ json_encode($actionResult['data']??[], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
        </div>
    </div>
    @endif

    {{-- Manual entry --}}
    <div class="card">
        <div class="card-head">
            <div style="font-size:14px;font-weight:600;color:#111;">Manual Entry</div>
            <span style="font-size:12px;color:#aaa;">for keys received via email</span>
        </div>
        <div style="padding:16px 18px;display:flex;flex-direction:column;gap:10px;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div>
                    <label class="label">License Key</label>
                    <input wire:model.defer="manualKey" type="text" placeholder="creem_lic_..." class="input" style="font-family:monospace;font-size:13px;">
                </div>
                <div>
                    <label class="label">Instance Name</label>
                    <input wire:model.defer="instanceName" type="text" placeholder='e.g. "My MacBook Pro"' class="input" style="font-size:13px;">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">
                <button wire:click="activateManual" @disabled(!$isConfigured) class="btn btn-indigo" style="justify-content:center;">Activate</button>
                <button wire:click="validateManual" @disabled(!$isConfigured) class="btn btn-ghost" style="justify-content:center;">Validate</button>
                <button wire:click="deactivateManual" @disabled(!$isConfigured) class="btn btn-danger" style="justify-content:center;">Deactivate</button>
            </div>
        </div>
    </div>
</div>
