@extends('creemdemo::layouts.master')

@section('content')

{{-- ABOUT --}}
<div x-show="tab==='about'" x-transition>

    {{-- Intro card: full width --}}
    <div class="card" style="margin-bottom:20px;">
        <div style="padding:22px 24px;display:flex;align-items:center;gap:16px;">
            <div style="width:44px;height:44px;background:linear-gradient(135deg,#4f46e5,#7c3aed);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg style="width:22px;height:22px;color:#fff" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
            </div>
            <div>
                <div style="font-size:18px;font-weight:800;color:#111;margin-bottom:4px;">Laravel Creem Demo</div>
                <p style="font-size:13.5px;color:#666;line-height:1.65;margin:0;">
                    Interactive demo for the <a href="https://github.com/romansh/laravel-creem" target="_blank" style="color:#4f46e5;font-weight:600;text-decoration:none;">laravel-creem</a> package —
                    a full-featured Laravel SDK for the <a href="https://creem.io" target="_blank" style="color:#4f46e5;font-weight:600;text-decoration:none;">Creem</a> payment platform.
                    Connect your test API key in <strong>API Setup</strong> and explore every feature live against the real API.
                </p>
            </div>
        </div>
    </div>

    {{-- Two-column: Quick Start + Test Cards on left, Feature tiles on right --}}
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;">

        {{-- LEFT: Quick Start steps + Test Cards --}}
        <div>
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#aaa;margin-bottom:10px;">Quick Start</div>
            <div class="card" style="margin-bottom:16px;">
                <div style="padding:20px;display:flex;flex-direction:column;gap:16px;">
                    @foreach([
                        ['1','Get your test API key','Sign up at <a href="https://creem.io" target="_blank" style="color:#4f46e5;">creem.io</a> → Dashboard → Developers → API Keys. Copy the key starting with <code style="background:#f3f4f6;padding:1px 5px;border-radius:3px;font-size:12px;">creem_test_</code>.'],
                        ['2','Connect in API Setup','Open the <strong>API Setup</strong> tab, paste your key, and click <strong>Save &amp; Connect</strong>. The stats bar will turn green.'],
                        ['3','Create a product','Switch to <strong>One-Time Payments</strong> and click <strong>+ Random Product</strong> to create a product via the API.'],
                        ['4','Run a test checkout','Click <strong>Buy</strong> on any product. You\'ll be redirected to a real Creem checkout. Use test card <code style="background:#f3f4f6;padding:1px 5px;border-radius:3px;font-size:12px;">4242 4242 4242 4242</code>.'],
                        ['5','Watch webhooks fire','After payment, open <strong>Webhooks &amp; Access</strong> to see the captured <code style="background:#f3f4f6;padding:1px 5px;border-radius:3px;font-size:12px;">checkout.completed</code> event.'],
                    ] as [$num,$title,$desc])
                    <div style="display:flex;gap:12px;align-items:flex-start;">
                        <div style="width:26px;height:26px;border-radius:50%;background:#eef2ff;color:#4f46e5;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;flex-shrink:0;margin-top:1px;">{{ $num }}</div>
                        <div>
                            <div style="font-size:13px;font-weight:700;color:#111;">{{ $title }}</div>
                            <div style="font-size:12px;color:#888;line-height:1.6;margin-top:2px;">{!! $desc !!}</div>
                        </div>
                    </div>
                    @endforeach

                    {{-- Localhost tunnel note --}}
                    <div style="display:flex;gap:10px;align-items:flex-start;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 12px;">
                        <div style="font-size:13px;flex-shrink:0;margin-top:1px;">⚡</div>
                        <div style="font-size:12px;color:#92400e;line-height:1.6;">
                            <strong>Running on localhost?</strong> Creem needs a public HTTPS URL to deliver webhooks.
                            Expose your local app with <a href="https://ngrok.com" target="_blank" style="color:#b45309;font-weight:600;">ngrok</a>
                            (<code style="background:#fef3c7;padding:1px 4px;border-radius:3px;">ngrok http 8000</code>)
                            or <a href="https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/" target="_blank" style="color:#b45309;font-weight:600;">Cloudflare Tunnels</a>,
                            then add the generated URL as a webhook endpoint in your Creem dashboard.
                        </div>
                    </div>
                </div>
            </div>

            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#aaa;margin-bottom:10px;">Test Cards</div>
            <div class="card">
                <div style="padding:12px 16px;display:flex;flex-direction:column;gap:8px;">
                    @foreach([
                        ['4242 4242 4242 4242','Success','green'],
                        ['4000 0000 0000 9995','Decline','red'],
                        ['4000 0025 0000 3155','3DS Auth','amber'],
                    ] as [$card,$label,$c])
                    <div style="display:flex;align-items:center;justify-content:space-between;background:#f9f9fb;border:1px solid #ebebed;border-radius:7px;padding:8px 12px;">
                        <div>
                            <code style="font-size:13px;font-weight:700;color:#111;">{{ $card }}</code>
                            <span class="badge badge-{{ $c }}" style="margin-left:8px;">{{ $label }}</span>
                        </div>
                        <button onclick="navigator.clipboard.writeText('{{ str_replace(' ','',$card) }}').then(()=>{this.textContent='✓';setTimeout(()=>this.textContent='Copy',1500)})"
                                style="font-size:12px;font-weight:600;color:#6366f1;background:none;border:none;cursor:pointer;padding:4px 8px;border-radius:5px;">Copy</button>
                    </div>
                    @endforeach
                    <p style="font-size:11px;color:#bbb;margin:2px 0 0;">Any future expiry · any 3-digit CVV</p>
                </div>
            </div>
        </div>

        {{-- RIGHT: Feature tiles --}}
        <div>
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#aaa;margin-bottom:10px;">What you can do</div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px;">
                @foreach([
                    ['🛍️','One-Time Payments','Create products, generate checkout links, process real payments.','products'],
                    ['🔄','Subscriptions','Recurring plans with 4 billing periods (monthly to yearly).','subscriptions'],
                    ['🏷️','Discounts','Percentage or fixed-amount coupon codes applied at checkout.','discounts'],
                    ['📡','Webhooks','Live events: checkout.completed, subscription.paid, etc.','webhooks'],
                    ['🔐','Access Control','GrantAccess / RevokeAccess driven by webhook events.','webhooks'],
                    ['💳','Transactions','Browse real payment transactions from the Creem API.','transactions'],
                    ['👤','Multi-Profile','Switch API environments (sandbox ↔ production) on the fly.','setup'],
                    ['📋','Code Examples','Copy-ready Laravel snippets on every tab.','products'],
                ] as [$icon,$title,$desc,$target])
                <div style="background:#fff;border:1px solid #e8e8ec;border-radius:10px;padding:14px;transition:border-color .15s;cursor:pointer;"
                     onmouseover="this.style.borderColor='#c7d2fe'" onmouseout="this.style.borderColor='#e8e8ec'"
                     @click="setTab('{{ $target }}')">
                    <div style="font-size:20px;margin-bottom:6px;">{{ $icon }}</div>
                    <div style="font-size:13px;font-weight:700;color:#111;margin-bottom:3px;">{{ $title }}</div>
                    <div style="font-size:11.5px;color:#999;line-height:1.45;">{{ $desc }}</div>
                </div>
                @endforeach
            </div>
        </div>
    </div>

</div>

{{-- SETUP --}}
<div x-show="tab==='setup'" x-transition>
    <div>
        @livewire('creemdemo::configuration-form')
    </div>
</div>

{{-- PRODUCTS --}}
<div x-show="tab==='products'" x-transition>
    <div style="display:grid;grid-template-columns:1fr minmax(300px,420px);gap:20px;">
        @livewire('creemdemo::products-list')
        <div class="code-wrap">
            <div class="code-topbar">
                <span style="font-size:11px;font-weight:600;color:#666;text-transform:uppercase;letter-spacing:.06em;">Code Example</span>
            </div>
            <pre><code>@verbatim
// Create a one-time product
$product = Creem::products()->create([
    'name'         => 'My Digital Product',
    'price'        => 2999,   // in cents
    'currency'     => 'USD',
    'billing_type' => 'onetime',
    'tax_mode'     => 'inclusive',
    'tax_category' => 'saas', // string, not array
]);

// Create checkout session
$checkout = Creem::checkouts()->create([
    'product_id'  => $product['id'],
    'customer'    => ['email' => $user->email],
    'success_url' => route('dashboard'),
    'metadata'    => ['user_id' => $user->id],
]);

// IMPORTANT: always guard against null url
$url = $checkout['checkout_url'] ?? null;
if (!$url) {
    // Log the error for debugging
    Log::error('Creem checkout_url missing', [
        'product_id' => $product['id'],
        'user_id' => $user->id,
        'response' => $checkout,
    ]);

    // Display a user-friendly error message
    return redirect()->route('dashboard')->withErrors([
        'checkout' => 'Unable to create checkout session. Please try again later.',
    ]);
}

return redirect($url);
@endverbatim</code></pre>
        </div>
    </div>
</div>

{{-- SUBSCRIPTIONS --}}
<div x-show="tab==='subscriptions'" x-transition>
    <div style="display:grid;grid-template-columns:1fr minmax(300px,420px);gap:20px;">
        @livewire('creemdemo::subscriptions-list')
        <div class="code-wrap">
            <div class="code-topbar">
                <span style="font-size:11px;font-weight:600;color:#666;text-transform:uppercase;letter-spacing:.06em;">Code Example</span>
                <button onclick="copyCode(this)" style="font-size:12px;color:#888;background:none;border:none;cursor:pointer;padding:2px 8px;border-radius:4px;" onmouseover="this.style.color='#cdd6f4'" onmouseout="this.style.color='#888'">Copy</button>
            </div>
            <pre><code>@verbatim
// All valid billing_period values:
// every-month | every-three-months
// every-six-months | every-year

$plan = Creem::products()->create([
    'name'           => 'Pro Plan',
    'price'          => 2900,
    'currency'       => 'USD',
    'billing_type' => 'recurring',
    'billing_period' => 'every-month',
    'tax_mode'       => 'inclusive',
    'tax_category'   => 'saas',
]);

$checkout = Creem::checkouts()->create([
    'product_id'  => $plan['id'],
    'customer'    => ['email' => $user->email],
    'success_url' => route('dashboard'),
]);

// Guard against null checkout_url
$url = $checkout['checkout_url'] ?? null;
if (!$url) {
    Log::error('Checkout failed', [
        'product' => $plan['id']
    ]);
    return back()->withErrors([
        'payment' => 'Checkout unavailable'
    ]);
}

return redirect($url);

// Manage subscriptions via real API
Creem::subscriptions()->cancel($subId);
Creem::subscriptions()->pause($subId);
Creem::subscriptions()->resume($subId);
@endverbatim</code></pre>
        </div>
    </div>
</div>

{{-- DISCOUNTS --}}
<div x-show="tab==='discounts'" x-transition>
    <div style="display:grid;grid-template-columns:1fr minmax(300px,420px);gap:20px;">
        @livewire('creemdemo::discount-demo')
        <div class="code-wrap">
            <div class="code-topbar">
                <span style="font-size:11px;font-weight:600;color:#666;text-transform:uppercase;letter-spacing:.06em;">Code Example</span>
                <button onclick="copyCode(this)" style="font-size:12px;color:#888;background:none;border:none;cursor:pointer;padding:2px 8px;border-radius:4px;" onmouseover="this.style.color='#cdd6f4'" onmouseout="this.style.color='#888'">Copy</button>
            </div>
            <pre><code>@verbatim
// Percentage discount for specific products
Creem::discounts()->create([
    'name'        => 'Launch Special',
    'type'        => 'percentage',
    'percentage'  => 30,        // 30%
    'code'        => 'LAUNCH30',
    'duration'    => 'forever',
    'currency'    => 'USD',
    'applies_to_products' => [$productId],
]);

// Fixed-amount discount for multiple products
$productIds = ['prod_123', 'prod_456'];
Creem::discounts()->create([
    'name'               => '$10 Off Bundle',
    'type'               => 'fixed',
    'amount'             => 1000,     // $10.00 in cents
    'code'               => 'SAVE10',
    'duration'           => 'once',
    'currency'           => 'USD',
    'applies_to_products'=> $productIds,
]);

// Apply discount at checkout
Creem::checkouts()->create([
    'product_id'    => $productId,
    'discount_code' => 'LAUNCH30',
    'customer'      => ['email' => $user->email],
]);
@endverbatim</code></pre>
        </div>
    </div>
</div>

{{-- TRANSACTIONS --}}
<div x-show="tab==='transactions'" x-transition>
    <div style="display:grid;grid-template-columns:1fr minmax(300px,420px);gap:20px;align-items:start;">
        @livewire('creemdemo::transactions-list')
        <div class="code-wrap" style="display:flex;flex-direction:column;">
            <div class="code-topbar">
                <span style="font-size:11px;font-weight:600;color:#cbd5e1;text-transform:uppercase;letter-spacing:.06em;">Code Example</span>
                <button onclick="copyCode(this)" style="font-size:12px;color:#888;background:none;border:none;cursor:pointer;padding:2px 8px;border-radius:4px;">Copy</button>
            </div>
            <pre><code>@verbatim
// List transactions
use Romansh\LaravelCreem\Facades\Creem;

// Apply session config first (demo uses session-backed profiles)
\Modules\CreemDemo\Livewire\ConfigurationForm::applySessionConfig();

$txns = Creem::profile('default')->transactions()->list([
    'page_number' => 1,
    'page_size'   => 20,
]);

foreach ($txns['items'] ?? [] as $t) {
    $id = $t['id'] ?? $t['transaction_id'];
    $amount = isset($t['amount']) ? number_format($t['amount'] / 100, 2) : null;
    $customer = data_get($t, 'customer') ?? $t['customer_id'];
    echo "{$id} — {$amount} — customer: {$customer}\n";
}
@endverbatim</code></pre>
        </div>
    </div>
</div>
{{-- WEBHOOKS --}}
<div x-show="tab==='webhooks'" x-transition>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
        <div style="display:flex;flex-direction:column;gap:16px;">
            @php
                $cfg = session('creem_demo_config', []);
                $active = session('creem_demo_active_profile', 'default');
                $webhookUrl = $cfg[$active]['webhook_url'] ?? null;
            @endphp
            @if($webhookUrl)
            <div style="padding:12px 18px;border-bottom:1px solid #eef2ff;background:#f8fafc;">
                <div style="font-size:11px;font-weight:700;color:#6366f1;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;">
                    📡 Webhook URL for <strong>{{ $active }}</strong>
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <input type="text" readonly
                           value="{{ $webhookUrl }}"
                           onclick="this.select()"
                           style="flex:1;font-family:monospace;font-size:12px;padding:7px 10px;border:1px solid #e6eefc;border-radius:6px;background:#fff;color:#3730a3;cursor:pointer;">
                    <button onclick="navigator.clipboard.writeText('{{ $webhookUrl }}');this.textContent='✓';setTimeout(()=>this.textContent='Copy',1500)"
                            style="padding:7px 12px;font-size:12px;font-weight:600;border:1px solid #c7d2fe;border-radius:6px;background:#eff0fb;color:#4f46e5;cursor:pointer;white-space:nowrap;">
                        Copy
                    </button>
                </div>
                <div style="font-size:10.5px;color:#6b7280;margin-top:5px;">
                    Paste this URL in your Creem dashboard → Webhooks. Session stays active for 10 min; heartbeat refreshes cache every 9 minutes.
                </div>
            </div>
            @endif

            @livewire('creemdemo::access-log')
            @livewire('creemdemo::webhook-logs')
        </div>
        <div style="display:flex;flex-direction:column;gap:16px;">
            {{-- Event mapping --}}
            <div class="card">
                <div class="card-head">
                    <span style="font-size:13.5px;font-weight:600;color:#111;">Event → Action Mapping</span>
                </div>
                <div style="padding:14px 18px;display:flex;flex-direction:column;gap:8px;">
                    @foreach([
                        ['checkout.completed','GrantAccess','green'],
                        ['subscription.paid','GrantAccess','green'],
                        ['subscription.canceled','RevokeAccess','red'],
                        ['subscription.expired','RevokeAccess','red'],
                    ] as [$ev,$action,$c])
                    <div style="display:flex;align-items:center;gap:10px;">
                        <code style="background:#f3f4f6;color:#374151;padding:3px 8px;border-radius:4px;font-size:12px;flex:1;">{{ $ev }}</code>
                        <span style="color:#d1d5db;">→</span>
                        <span class="badge badge-{{ $c === 'green' ? 'green' : 'red' }}" style="font-family:monospace;">{{ $action }}</span>
                    </div>
                    @endforeach
                </div>
            </div>

            {{-- Code --}}
            <div class="code-wrap">
                <div class="code-topbar">
                    <span style="font-size:11px;font-weight:600;color:#666;text-transform:uppercase;letter-spacing:.06em;">AppServiceProvider</span>
                    <button onclick="copyCode(this)" style="font-size:12px;color:#888;background:none;border:none;cursor:pointer;padding:2px 8px;border-radius:4px;" onmouseover="this.style.color='#cdd6f4'" onmouseout="this.style.color='#888'">Copy</button>
                </div>
                <pre><code>@verbatim
use Romansh\LaravelCreem\Events\GrantAccess;
use Romansh\LaravelCreem\Events\RevokeAccess;

// In AppServiceProvider::boot()
Event::listen(GrantAccess::class,
    function (GrantAccess $event) {
        // $event->customer['email']
        // $event->metadata  (from checkout)
        User::where('email',
            $event->customer['email'])
            ->update(['is_pro' => true]);
    }
);

Event::listen(RevokeAccess::class,
    function (RevokeAccess $event) {
        User::where('email',
            $event->customer['email'])
            ->update(['is_pro' => false]);
    }
);
@endverbatim</code></pre>
            </div>

            {{-- Test cards --}}
            <div class="card">
                <div class="card-head">
                    <span style="font-size:13.5px;font-weight:600;color:#111;">🧪 Test Cards</span>
                </div>
                <div style="padding:12px 16px;display:flex;flex-direction:column;gap:8px;">
                    @foreach([
                        ['4242 4242 4242 4242','Success','green'],
                        ['4000 0000 0000 9995','Decline','red'],
                        ['4000 0025 0000 3155','3DS Auth','amber'],
                    ] as [$card,$label,$c])
                    <div style="display:flex;align-items:center;justify-content:space-between;background:#f9f9fb;border:1px solid #ebebed;border-radius:7px;padding:8px 12px;">
                        <div>
                            <code style="font-size:13px;font-weight:700;color:#111;">{{ $card }}</code>
                            <span class="badge badge-{{ $c }}" style="margin-left:8px;">{{ $label }}</span>
                        </div>
                        <button onclick="navigator.clipboard.writeText('{{ str_replace(' ','',$card) }}').then(()=>{this.textContent='✓';setTimeout(()=>this.textContent='Copy',1500)})"
                                style="font-size:12px;font-weight:600;color:#6366f1;background:none;border:none;cursor:pointer;padding:4px 8px;border-radius:5px;transition:background .12s;"
                                onmouseover="this.style.background='#eef2ff'" onmouseout="this.style.background='none'">Copy</button>
                    </div>
                    @endforeach
                    <p style="font-size:11px;color:#bbb;margin-top:2px;">Any future expiry · any 3-digit CVV</p>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection
