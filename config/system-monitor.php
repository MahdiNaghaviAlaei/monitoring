<?php

return [
    'url' => env('SYSTEM_MONITOR_URL', ''),
    'protected_containers' => array_values(array_filter(array_map('trim', explode(',', (string) env('SYSTEM_MONITOR_PROTECTED_CONTAINERS', 'system_monitor'))))),
];
