<div wire:poll.5s="loadLogs">
    <div class="card">
        <div class="card-head">
            <div style="display:flex;align-items:center;gap:8px;">
                <span style="width:7px;height:7px;border-radius:50%;background:#22c55e;flex-shrink:0;" class="blink"></span>
                <span style="font-size:14px;font-weight:600;color:#111;">Event Log</span>
                <span class="badge badge-gray">{{ count($logs) }}</span>
            </div>
            @if(count($logs) > 0)
            <button wire:click="clearLogs" style="background:none;border:none;cursor:pointer;font-size:12px;color:#ccc;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#ccc'">Clear</button>
            @endif
        </div>

        @if(empty($logs))
        <div style="padding:36px;text-align:center;color:#bbb;">
            <div style="font-size:32px;margin-bottom:10px;">📡</div>
            <div style="font-size:14px;font-weight:500;margin-bottom:4px;">Waiting for events…</div>
            <div style="font-size:12px;color:#ccc;max-width:280px;margin:0 auto 12px;">Register your URL in Creem Dashboard, then complete a test checkout.</div>
            <div style="display:inline-block;text-align:left;background:#f9f9fb;border:1px solid #ebebed;border-radius:8px;padding:12px 16px;font-size:12px;color:#888;">
                <div style="font-weight:600;color:#555;margin-bottom:4px;">URL to register:</div>
                <code style="background:#ebebed;padding:3px 8px;border-radius:4px;font-size:11.5px;">https://your-domain.com/creem/webhook</code>
            </div>
        </div>
        @else
        <div>
            @foreach($logs as $i => $log)
            @php
                $ev = $log['event'] ?? 'unknown';
                $badges = ['checkout.completed'=>'badge-blue','subscription.active'=>'badge-green','subscription.paid'=>'badge-green','subscription.canceled'=>'badge-red','subscription.expired'=>'badge-amber','subscription.paused'=>'badge-amber','refund.created'=>'badge-amber','dispute.created'=>'badge-red'];
                $badge = $badges[$ev] ?? 'badge-gray';
            @endphp
            <div wire:key="wh-{{ $i }}">
                <div style="display:flex;align-items:center;gap:12px;padding:10px 18px;border-bottom:1px solid #f5f5f7;cursor:pointer;"
                     wire:click="toggleExpand({{ $i }})"
                     onmouseover="this.style.background='#fafafa'" onmouseout="this.style.background=''">
                    <span class="badge {{ $badge }}" style="font-family:monospace;flex-shrink:0;">{{ $ev }}</span>
                    <span style="font-size:12px;color:#aaa;flex:1;">{{ $log['created_at'] }}</span>
                    <svg style="width:14px;height:14px;color:#ccc;flex-shrink:0;transition:transform .15s;{{ $expandedIndex===$i ? 'transform:rotate(180deg)' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </div>
                @if($expandedIndex === $i)
                <div class="code-wrap" style="margin:0 16px 12px;border-radius:7px;">
                    <pre style="font-size:11px;">{{ json_encode($log['payload']??[], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
                </div>
                @endif
            </div>
            @endforeach
        </div>
        <div style="padding:9px 18px;border-top:1px solid #f5f5f7;font-size:11px;color:#ccc;">
            Resend: Creem Dashboard → Developers → Webhooks → Resend
        </div>
        @endif
    </div>
</div>
