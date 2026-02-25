<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Creem · Laravel Demo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Alpine is provided by the app bundle; avoid loading CDN duplicate -->
    @livewireStyles
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body { font-family: 'Inter', system-ui, sans-serif; background: #f7f7f8; color: #111; -webkit-font-smoothing: antialiased; }
        [x-cloak] { display: none !important; }
        pre, code { font-family: 'JetBrains Mono','Cascadia Code','Fira Code','Consolas',monospace; }

        /* === SIDEBAR === */
        #sidebar {
            width: var(--sb-w, 240px);
            min-width: 200px; max-width: 360px;
            background: #fff;
            border-right: 1px solid #e8e8ec;
            display: flex; flex-direction: column;
            height: 100vh; position: sticky; top: 0;
            flex-shrink: 0; overflow: hidden;
        }
        #resizer {
            width: 5px; flex-shrink: 0; cursor: col-resize;
            background: transparent; transition: background .15s;
        }
        #resizer:hover, #resizer.active { background: #6366f1; opacity: .25; }

        /* === NAV === */
        .nav-section { font-size: 10px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #aaa; padding: 16px 14px 4px; }
        .nav-link {
            display: flex; align-items: center; gap: 9px;
            padding: 7px 12px; margin: 1px 6px; border-radius: 6px;
            font-size: 13.5px; font-weight: 500; color: #555; cursor: pointer;
            transition: background .12s, color .12s; white-space: nowrap; overflow: hidden;
            user-select: none;
        }
        .nav-link:hover { background: #f3f3f5; color: #111; }
        .nav-link.active { background: #eeeeff; color: #4338ca; font-weight: 600; }
        .nav-link svg { flex-shrink: 0; width: 15px; height: 15px; }
        .nav-link span.label { overflow: hidden; text-overflow: ellipsis; }

        /* === CARDS === */
        .card { background: #fff; border: 1px solid #e8e8ec; border-radius: 10px; overflow: hidden; }
        .card-head { padding: 14px 18px; border-bottom: 1px solid #f0f0f2; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .card-body { padding: 18px; }

        /* === BUTTONS === */
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 7px; font-size: 13.5px; font-weight: 600; cursor: pointer; transition: all .12s; border: 1px solid transparent; white-space: nowrap; }
        .btn:disabled { opacity: .45; cursor: not-allowed; }
        .btn-indigo { background: #4f46e5; color: #fff; border-color: #4338ca; }
        .btn-indigo:hover:not(:disabled) { background: #4338ca; }
        .btn-green { background: #059669; color: #fff; border-color: #047857; }
        .btn-green:hover:not(:disabled) { background: #047857; }
        .btn-violet { background: #7c3aed; color: #fff; border-color: #6d28d9; }
        .btn-violet:hover:not(:disabled) { background: #6d28d9; }
        .btn-ghost { background: #fff; color: #444; border-color: #e2e2e6; }
        .btn-ghost:hover:not(:disabled) { background: #f7f7f8; border-color: #ccc; }
        .btn-danger { background: #fff; color: #dc2626; border-color: #fecaca; }
        .btn-danger:hover:not(:disabled) { background: #fef2f2; }
        .btn-sm { padding: 5px 10px; font-size: 12px; border-radius: 6px; }

        /* === BADGES === */
        .badge { display: inline-flex; align-items: center; padding: 2px 7px; border-radius: 4px; font-size: 11px; font-weight: 600; }
        .badge-green { background: #dcfce7; color: #15803d; }
        .badge-red { background: #fee2e2; color: #b91c1c; }
        .badge-amber { background: #fef3c7; color: #b45309; }
        .badge-blue { background: #dbeafe; color: #1d4ed8; }
        .badge-purple { background: #ede9fe; color: #6d28d9; }
        .badge-gray { background: #f3f4f6; color: #6b7280; }

        /* === FORM === */
        .input { width: 100%; border: 1px solid #e2e2e6; border-radius: 7px; padding: 8px 12px; font-size: 13.5px; color: #111; background: #fff; outline: none; transition: border-color .15s, box-shadow .15s; }
        .input:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,.1); }
        .input::placeholder { color: #bbb; }
        .label { display: block; font-size: 11px; font-weight: 700; color: #888; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 5px; }

        /* === CODE === */
        .code-wrap { background: #1a1b26; border-radius: 9px; overflow: visible; display:flex; flex-direction:column; width:100%; max-width:640px; margin:0 auto; align-self:flex-start; }
        .code-wrap pre { padding: 18px; font-size: 12px; line-height: 1.6; color: #c0caf5; overflow-x: auto; margin: 0; white-space: pre; }
        @media (max-width: 900px) {
            .code-wrap { max-width:100%; margin:0; }
        }
        .code-wrap .code-topbar { padding: 8px 14px; background: #13131e; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #2a2b3d; }

        /* === MISC === */
        @keyframes fadeUp { from{opacity:0;transform:translateY(5px)} to{opacity:1;transform:translateY(0)} }
        .fade-up { animation: fadeUp .16s ease-out; }
        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:.2} }
        .blink { animation: blink 2s ease-in-out infinite; }
        @keyframes spin { from{transform:rotate(0deg)} to{transform:rotate(360deg)} }
        .divider { border: 0; border-top: 1px solid #f0f0f2; margin: 0; }

        /* === STATS STRIP === */
        .stats-strip { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
        .stat-tile { background: #f7f7f8; border: 1px solid #ebebed; border-radius: 8px; padding: 10px 12px; text-align: center; }
        .stat-tile .num { font-size: 22px; font-weight: 800; color: #111; line-height: 1.1; }
        .stat-tile .lbl { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: #aaa; margin-top: 2px; }

        /* === MOBILE === */
        @media (max-width: 767px) {
            #sidebar, #resizer { display: none; }
            .mobile-bar { display: flex !important; }
        }
        @media (min-width: 768px) {
            .mobile-bar { display: none !important; }
            #mobile-drawer { display: none !important; }
        }
    </style>
</head>
<body x-data="{
    tab: localStorage.getItem('creem_demo_tab') || 'setup',
    sbW: 240,
    dragging: false,
    sx: 0, sw: 0,
    mOpen: false,
    setTab(t) {
        this.tab = t; localStorage.setItem('creem_demo_tab', t);
    },
    startResize(e) {
        this.dragging=true; this.sx=e.clientX; this.sw=this.sbW;
        document.body.style.cursor='col-resize'; document.body.style.userSelect='none';
        document.getElementById('resizer').classList.add('active');
    },
    doResize(e) {
        if(!this.dragging) return;
        this.sbW = Math.min(360, Math.max(200, this.sw + e.clientX - this.sx));
        document.getElementById('sidebar').style.setProperty('--sb-w', this.sbW+'px');
        document.getElementById('sidebar').style.width = this.sbW+'px';
    },
    stopResize() {
        if(!this.dragging) return;
        this.dragging=false; document.body.style.cursor=''; document.body.style.userSelect='';
        document.getElementById('resizer').classList.remove('active');
    }
}" @mousemove="doResize($event)" @mouseup="stopResize()" @set-tab.window="setTab($event.detail)">

{{-- Mobile bar --}}
<div class="mobile-bar bg-white border-b border-gray-200 h-12 px-4 flex items-center justify-between sticky top-0 z-50">
    <div class="flex items-center gap-2">
        <div class="w-7 h-7 rounded-lg bg-indigo-600 flex items-center justify-center">
            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
        </div>
        <span class="font-semibold text-sm">laravel-creem</span>
    </div>
    <button @click="mOpen=!mOpen" class="p-1.5 rounded-lg hover:bg-gray-100">
        <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>
</div>
<div id="mobile-drawer" x-show="mOpen" @click.outside="mOpen=false"
     class="fixed top-12 left-0 right-0 bg-white border-b border-gray-200 z-50 shadow-lg p-2">
    @foreach([
        ['about','About','M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
        ['setup','API Setup','M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z'],
        ['products','One-Time Payments','M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z'],
        ['subscriptions','Subscriptions','M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15'],
        ['discounts','Discounts','M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z'],
        ['transactions','Transactions','M12 8c-1.657 0-3 1.343-3 3v6h6v-6c0-1.657-1.343-3-3-3zM4 6h16v2H4V6z'],
        ['webhooks','Webhooks & Access','M13 10V3L4 14h7v7l9-11h-7z'],
    ] as [$s, $l, $p])
    <div class="nav-link" data-tab="{{ $s }}" :class="{'active':tab==='{{ $s }}'}" @click="setTab('{{ $s }}'); mOpen=false">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="{{ $p }}"/></svg>
        <span class="label">{{ $l }}</span>
    </div>
    @endforeach
</div>

<div style="display:flex; height:100vh; min-height:0; overflow:hidden;">

{{-- ──── SIDEBAR ──── --}}
<aside id="sidebar">
    {{-- Logo (links to demo home) --}}
    <div style="padding:18px 16px 14px; border-bottom:1px solid #ebebed; flex-shrink:0;">
        <a href="{{ route('creem-demo.index') }}"
           style="display:flex;align-items:center;gap:12px;text-decoration:none;" class="group">
            <div style="width:40px;height:40px;background:linear-gradient(135deg,#4f46e5,#7c3aed);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 2px 8px rgba(79,70,229,.3);">
                <svg style="width:22px;height:22px;color:#fff" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
            </div>
            <div style="min-width:0;">
                <div style="font-size:15px;font-weight:700;color:#111;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Laravel Creem Demo</div>
                <div style="font-size:11px;color:#999;margin-top:2px;">Demo</div>
            </div>
        </a>
    </div>

    {{-- Nav --}}
    <nav style="flex:1; overflow-y:hidden; padding:8px 0;">
        <div class="nav-section">Configuration</div>
        <div class="nav-link" data-tab="about" :class="{'active':tab==='about'}" @click="setTab('about')">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span class="label">About</span>
        </div>
        <div class="nav-link" data-tab="setup" :class="{'active':tab==='setup'}" @click="setTab('setup')">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
            <span class="label">API Setup</span>
        </div>

        <div class="nav-section" style="margin-top:4px;">Products & Billing</div>
        <div class="nav-link" data-tab="products" :class="{'active':tab==='products'}" @click="setTab('products')">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
            <span class="label">One-Time Payments</span>
        </div>
        <div class="nav-link" data-tab="subscriptions" :class="{'active':tab==='subscriptions'}" @click="setTab('subscriptions')">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            <span class="label">Subscriptions</span>
        </div>
        <div class="nav-link" data-tab="discounts" :class="{'active':tab==='discounts'}" @click="setTab('discounts')">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
            <span class="label">Discounts</span>
        </div>

        <div class="nav-link" data-tab="transactions" :class="{'active':tab==='transactions'}" @click="setTab('transactions')">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 8c-1.657 0-3 1.343-3 3v6h6v-6c0-1.657-1.343-3-3-3zM4 6h16v2H4V6z"/></svg>
            <span class="label">Transactions</span>
        </div>

        <div class="nav-section" style="margin-top:4px;">Advanced</div>
        <div class="nav-link" data-tab="webhooks" :class="{'active':tab==='webhooks'}" @click="setTab('webhooks')">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            <span class="label" style="flex:1;">Webhooks & Access</span>
            <span style="width:7px;height:7px;border-radius:50%;background:#22c55e;flex-shrink:0;" class="blink"></span>
        </div>
    </nav>

    {{-- Footer --}}
    <div style="padding:10px 14px 14px; border-top:1px solid #ebebed; flex-shrink:0;">
        <span class="badge badge-amber" style="margin-bottom:6px;">⚠ Test Mode</span>
        <a href="https://docs.creem.io" target="_blank" style="display:block;font-size:11.5px;color:#999;text-decoration:none;margin-top:4px;">API Docs ↗</a>
    </div>
</aside>

{{-- Resize handle --}}
<div id="resizer" @mousedown.prevent="startResize($event)"></div>

{{-- ──── MAIN ──── --}}
<div style="flex:1;min-width:0;display:flex;flex-direction:column;min-height:0;overflow:hidden;">

    {{-- Topbar --}}
    <div style="background:#fff;border-bottom:1px solid #e8e8ec;padding:0 24px;height:56px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;gap:16px;">
        <div>
        <div style="font-size:15px;font-weight:700;color:#111;" x-text="{about:'About',setup:'API Setup',products:'One-Time Payments',subscriptions:'Subscriptions',discounts:'Discounts',licenses:'Licenses',transactions:'Transactions',webhooks:'Webhooks & Access'}[tab]"></div>
            <div style="font-size:12px;color:#999;margin-top:1px;" x-text="{about:'What this demo can do',setup:'Manage credentials and profiles',products:'Create products and initiate checkouts',subscriptions:'Recurring plans — all 4 billing periods',discounts:'Create and manage coupon codes',licenses:'Activate, validate, and deactivate licenses',transactions:'Browse payment transactions',webhooks:'Live event log from package listener'}[tab]"></div>
        </div>

        {{-- Right-side controls: flash messages + GitHub link (separate container) --}}
        <div style="margin-left:auto;display:flex;align-items:center;gap:12px;flex-shrink:0;">
            <div id="flash-container" style="display:flex;align-items:center;gap:12px;">
                @if(session('success'))
                <div x-data="{v:true}" x-init="setTimeout(()=>v=false,4000)" x-show="v" x-transition
                     style="display:flex;align-items:center;gap:8px;background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d;padding:7px 14px;border-radius:8px;font-size:13px;">
                    <svg style="width:14px;height:14px;flex-shrink:0;" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    {{ session('success') }}
                </div>
                @endif
                @if(session('error'))
                <div x-data="{v:true}" x-init="setTimeout(()=>v=false,6000)" x-show="v" x-transition
                     style="display:flex;align-items:center;gap:8px;background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:7px 14px;border-radius:8px;font-size:13px;">
                    <svg style="width:14px;height:14px;flex-shrink:0;" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
                    {{ session('error') }}
                </div>
                @endif
            </div>

            <div id="github-container" style="flex-shrink:0;padding-left:12px;border-left:1px solid #eee;">
                <a href="https://github.com/romansh/laravel-creem" target="_blank" rel="noopener noreferrer"
                   style="display:inline-flex;align-items:center;gap:8px;font-size:15px;color:#374151;text-decoration:none;font-weight:600;">
                    <svg style="width:18px;height:18px;flex-shrink:0;" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z"/></svg>
                    <span style="line-height:1;">romansh/laravel-creem</span>
                </a>
            </div>
        </div>
    </div>

    {{-- Stats strip — only when configured --}}
    <div id="stats-bar" style="border-bottom:1px solid #ebebed; flex-shrink:0;">
        @livewire('creemdemo::dashboard-stats')
    </div>

    <style>
        @keyframes loading-gradient {
            0% {
                background-position: 200% 0;
            }
            100% {
                background-position: -200% 0;
            }
        }
    </style>

    {{-- Scrollable content --}}
    <div style="flex:1;overflow:auto;padding:24px;min-height:0;">
        @yield('content')
    </div>
</div>
</div>

@livewire('creemdemo::heartbeat')
@livewireScripts
<script>
// Ensure Livewire/Alpine re-initialize when navigating back from external checkout
window.addEventListener('pageshow', () => {
    // If Livewire exposes a restart method use it; otherwise force a soft reload
    try {
        if (window.Livewire && typeof window.Livewire.restart === 'function') {
            window.Livewire.restart();
        }
    } catch (e) {
        // best-effort; do nothing
    }
});
// Redirect to external Creem checkout URL
document.addEventListener('open-url', e => {
    window.location.href = e.detail.url;
});

    // Tab persistence: save on nav-link click, restore on load
    document.addEventListener('DOMContentLoaded', () => {
        const STORAGE_KEY = 'creem_demo_tab';
        const validTabs = ['about','setup','products','subscriptions','discounts','transactions','webhooks'];

        // Restore: set Alpine tab when available
        const saved = localStorage.getItem(STORAGE_KEY);
        if (saved && validTabs.includes(saved)) {
            const restore = () => {
                const body = document.body;
                if (body._x_dataStack && body._x_dataStack[0]) {
                    body._x_dataStack[0].tab = saved;
                    return true;
                }
                return false;
            };
            // Try a few times while Alpine initializes
            let tries = 0;
            const t = setInterval(() => {
                if (restore() || ++tries > 10) clearInterval(t);
            }, 60);
        }

        // Save on click using data-tab attribute (reliable even if Alpine internals change)
        document.querySelectorAll('.nav-link[data-tab]').forEach(el => {
            el.addEventListener('click', () => {
                const target = el.dataset.tab;
                if (target && validTabs.includes(target)) {
                    localStorage.setItem(STORAGE_KEY, target);
                }
            });
        });
    });

function copyCode(btn) {
    const pre = btn.closest('.code-wrap').querySelector('pre');
    navigator.clipboard.writeText(pre.innerText).then(() => {
        const orig = btn.innerHTML;
        btn.innerHTML = '✓ Copied';
        btn.style.color = '#22c55e';
        setTimeout(() => { btn.innerHTML = orig; btn.style.color = ''; }, 1800);
    });
}
</script>
</body>
</html>
