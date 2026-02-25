{{-- Heartbeat component to keep cache alive. Polls every 1 hour 59 minutes (cache TTL is 2 hours). --}}
{{-- Note: wire:poll only works when browser tab is active. Browser throttles inactive tabs. --}}
<div wire:poll.7140000ms="keepAlive" style="display:none;" aria-hidden="true"></div>
