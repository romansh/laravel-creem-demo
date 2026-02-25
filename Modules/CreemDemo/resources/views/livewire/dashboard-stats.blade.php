<div wire:init="loadStats" class="stats-container"
    x-data="{
        loading: true,
        timedOut: false,
        switchingTo: '',
        _timer: null,
        startTimer() {
            clearTimeout(this._timer);
            this._timer = setTimeout(() => {
                if (this.loading) {
                    this.loading = false;
                    this.timedOut = true;
                    window.dispatchEvent(new CustomEvent('stats-loaded', {detail:{configured:false}}));
                }
            }, 8000);
        }
    }"
    x-init="startTimer()"
    @profile-switched.window="timedOut=false; loading=true; switchingTo=$event.detail.name||''; startTimer()"
    @configuration-updated.window="timedOut=false; loading=true; startTimer()"
    @stats-loaded.window="clearTimeout(_timer); loading=false; timedOut=false; switchingTo=''"
    style="padding:10px 24px; background:#fafafa; position:relative; overflow:hidden; min-height:44px;">
    <style>
        @keyframes loading-gradient {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
    </style>

    {{-- Gradient shimmer: visible while loading --}}
    <div x-show="loading" x-transition.opacity.duration.200ms
         style="position:absolute;top:0;left:0;width:100%;height:100%;background:linear-gradient(90deg,#f8fbff 20%,#dbeafe 50%,#f8fbff 80%);background-size:200% 100%;animation:loading-gradient 2.2s infinite;z-index:10;"></div>

    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;min-height:24px;">

        {{-- Profile name + updating hint — above gradient --}}
        <div style="position:relative;z-index:20;display:flex;align-items:center;gap:8px;">
            <span style="font-size:12px;font-weight:600;color:#555;" x-text="switchingTo || $wire.profile">{{ $profile ?? 'default' }}</span>
            <span x-show="loading" x-transition style="font-size:11px;color:#999;font-weight:400;">— updating stats…</span>
        </div>

        {{-- Divider —  above gradient --}}
        <div style="position:relative;z-index:20;width:1px;height:18px;background:#e8e8ec;"></div>

        {{-- Timeout: no data yet — prompt to configure --}}
        <div x-show="!loading && timedOut" x-transition
             style="display:flex;align-items:center;gap:6px;font-size:12px;color:#aaa;">
            <svg style="width:12px;height:12px;flex-shrink:0;" viewBox="0 0 24 24" fill="none">
                <circle cx="12" cy="12" r="10" stroke="#d1d5db" stroke-width="2"/>
                <path d="M12 8v4m0 4h.01" stroke="#9ca3af" stroke-width="2" stroke-linecap="round"/>
            </svg>
            No stats yet — add your API key in
            <span style="font-weight:700;color:#6366f1;cursor:pointer;"
                  onclick="window.dispatchEvent(new CustomEvent('set-tab',{detail:'setup'}))">Setup</span>
        </div>

        {{-- Stats when data has arrived --}}
        <div x-show="!loading && !timedOut" style="display:flex;align-items:center;gap:20px;">

            {{-- Configured: real numbers (reactive via $wire) --}}
            <template x-if="$wire.isConfigured">
                <div style="display:flex;align-items:center;gap:20px;" x-transition>
                    <div style="text-align:center;">
                        <div style="font-size:20px;font-weight:800;color:#111;line-height:1;" x-text="$wire.totalProducts">{{ $totalProducts }}</div>
                        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#aaa;margin-top:1px;">Products</div>
                    </div>
                    <div style="text-align:center;">
                        <div style="font-size:20px;font-weight:800;color:#4f46e5;line-height:1;" x-text="$wire.onetimeProducts">{{ $onetimeProducts }}</div>
                        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#aaa;margin-top:1px;">One-Time</div>
                    </div>
                    <div style="text-align:center;">
                        <div style="font-size:20px;font-weight:800;color:#7c3aed;line-height:1;" x-text="$wire.subscriptionProducts">{{ $subscriptionProducts }}</div>
                        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#aaa;margin-top:1px;">Subscriptions</div>
                    </div>
                </div>
            </template>

        </div>

    </div>
</div>
