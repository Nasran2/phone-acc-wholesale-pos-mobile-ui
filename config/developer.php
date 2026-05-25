<?php

return [
    'username' => env('DEVELOPER_USERNAME', 'developer'),
    'password' => env('DEVELOPER_PASSWORD', 'password'),
    'session_key' => 'developer_authenticated',
    'maintenance_secret' => env('DEVELOPER_MAINTENANCE_SECRET', 'developer-maintenance'),
];
