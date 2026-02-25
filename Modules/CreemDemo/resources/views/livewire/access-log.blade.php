<div wire:poll.5s="loadAccesses" class="card">
    <div class="card-head">
        <div>
            <div style="font-size:14px;font-weight:600;color:#111;">Access Log</div>
            <div style="font-size:12px;color:#aaa;margin-top:2px;">
                <span style="background:#dcfce7;color:#15803d;padding:1px 6px;border-radius:3px;font-size:11px;font-family:monospace;font-weight:600;">GrantAccess</span>
                <span style="color:#ddd;margin:0 4px;">/</span>
                <span style="background:#fee2e2;color:#b91c1c;padding:1px 6px;border-radius:3px;font-size:11px;font-family:monospace;font-weight:600;">RevokeAccess</span>
            </div>
        </div>
        @if(count($accesses) > 0)
        <button wire:click="clearAccesses" style="background:none;border:none;cursor:pointer;font-size:12px;color:#ccc;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#ccc'">Clear</button>
        @endif
    </div>

    @if(empty($accesses))
    <div style="padding:32px;text-align:center;color:#bbb;"><div style="font-size:32px;margin-bottom:8px;">🔐</div><div style="font-size:13.5px;">No access events yet.</div><div style="font-size:12px;margin-top:4px;">Complete a test checkout to trigger GrantAccess.</div></div>
    @else
    <div>
        @foreach($accesses as $access)
        @php $granted = ($access['status']??'granted')==='granted'; @endphp
            <div style="display:flex;align-items:center;gap:10px;padding:10px 18px;border-bottom:1px solid #f5f5f7;"
             onmouseover="this.style.background='#fafafa'" onmouseout="this.style.background=''">
            <span style="width:7px;height:7px;border-radius:50%;flex-shrink:0;background:{{ $granted ? '#22c55e' : '#ef4444' }};"></span>
            <div style="flex:1;min-width:0;">
                <div style="font-size:13.5px;font-weight:500;color:#111;overflow:hidden;text-overflow:ellipsis;">{{ $access['email'] }}</div>
                <div style="font-size:11px;color:#888;margin-top:3px;">Product: {{ $access['product_id'] ?? '—' }}</div>
            </div>
            <span class="badge {{ $granted ? 'badge-green' : 'badge-red' }}">{{ $granted ? 'GRANTED' : 'REVOKED' }}</span>
            <span style="font-size:11px;color:#ccc;flex-shrink:0;">{{ \Carbon\Carbon::parse($access['at'])->diffForHumans() }}</span>
        </div>
        @endforeach
    </div>
    @endif
</div>
