<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:8000',
        'http://127.0.0.1:8000',
        'http://localhost:3000', // في حال تغير منفذ React
    ],

    // إضافة نمط للتعرف على أي رابط ngrok ديناميكياً
    'allowed_origins_patterns' => [
        '#^https?://.*\.ngrok-free\.app$#',
        '#^https?://.*\.ngrok\.io$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
