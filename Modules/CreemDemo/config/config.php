<?php

return [
    'name' => 'CreemDemo',
    'enabled' => env('CREEM_DEMO_ENABLED', true),
    'route_prefix' => 'creem-demo',
    'middleware' => ['web'],
];
