@extends('creemdemo::layouts.master')

@section('content')
<div style="padding:18px;">
    <div class="card">
        <div class="card-head">
            <div><div style="font-weight:700;font-size:16px;">Transactions</div><div style="color:#777;font-size:13px;margin-top:2px;">Recent payment transactions from Creem API</div></div>
        </div>
        <div class="card-body">
            <livewire:creemdemo::transactions-list />
        </div>
    </div>
</div>
@endsection
