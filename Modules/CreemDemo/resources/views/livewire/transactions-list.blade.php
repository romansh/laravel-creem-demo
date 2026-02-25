<div wire:init="loadTransactions" class="space-y-4">
    <div style="display:flex;justify-content:space-between;align-items:center;">
        <div style="font-weight:700;font-size:15px;">Transactions</div>
        @if($loading)
            <div style="font-size:13px;color:#888;">Loading…</div>
        @endif
    </div>

    @if(empty($transactions))
        <div style="color:#888;padding:18px;border-radius:8px;background:#fff;border:1px solid #f0f0f2;">No transactions found.</div>
    @endif

    <div style="display:grid;gap:10px;margin-top:6px;">
        @foreach($transactions as $txn)
                @php
                    // Product information is not fetched or displayed here — rely on transaction payload only if needed.

                    // Customer label
                    $cust = data_get($txn, 'customer');
                    $custId = is_array($cust) ? ($cust['id'] ?? null) : ($cust ?? data_get($txn, 'customer_id'));
                    if ($custId && isset($customerMap[$custId])) {
                        $custLabel = $customerMap[$custId];
                    } else {
                        if (is_array($cust)) {
                            $custLabel = data_get($cust, 'email') ?? data_get($cust, 'name') ?? data_get($cust, 'id') ?? '—';
                        } else {
                            $custLabel = $custId ?? ($cust ?? '—');
                        }
                    }

                    // Robust date parsing: accept ISO strings or numeric timestamps (seconds or ms)
                    $dt = data_get($txn, 'created_at') ?? data_get($txn, 'created') ?? data_get($txn, 'createdAt');
                    $readable = '';
                    if ($dt) {
                        if (is_numeric($dt)) {
                            $ts = (int) $dt;
                            // milliseconds -> convert to seconds
                            if (strlen((string) $dt) > 10) $ts = (int) floor($ts / 1000);
                            try {
                                $readable = \Carbon\Carbon::createFromTimestamp($ts)->format('Y-m-d H:i');
                            } catch (\Exception $e) {
                                $readable = (string) $dt;
                            }
                        } else {
                            try {
                                $readable = \Carbon\Carbon::parse($dt)->format('Y-m-d H:i');
                            } catch (\Exception $e) {
                                $readable = (string) $dt;
                            }
                        }
                    }
                @endphp

                <div style="background:#fff;border:1px solid #f0f0f2;padding:12px;border-radius:8px;display:flex;justify-content:space-between;align-items:flex-start;">
                    <div>
                        <div style="font-weight:700">{{ data_get($txn, 'id') ?? data_get($txn, 'transaction_id') ?? '—' }}</div>
                        <div style="color:#6b7280;font-size:13px;margin-top:4px;">Status: {{ data_get($txn, 'status') ?? '—' }} · Amount: {{ isset($txn['amount']) ? ('$' . number_format($txn['amount']/100,2)) : (data_get($txn, 'amount') ? ('$' . number_format(data_get($txn, 'amount')/100,2)) : '—') }}</div>
                        <div style="color:#9ca3af;font-size:13px;margin-top:4px;">Customer: {{ $custLabel }}</div>
                    </div>
                    <div style="color:#9ca3af;font-size:12px;min-width:120px;text-align:right;">{{ $readable }}</div>
                </div>
            @endforeach
    </div>

    <div style="display:flex;align-items:center;justify-content:space-between;margin-top:10px;">
        <button wire:click="prevPage" class="btn btn-ghost btn-sm" @if($page <= 1) disabled @endif>Prev</button>
        <div style="color:#6b7280">Page {{ $page }}</div>
        <button wire:click="nextPage" class="btn btn-ghost btn-sm">Next</button>
    </div>
</div>
