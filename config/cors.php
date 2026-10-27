<?php

// The embedded frontend is a separate codebase on its own origin (FRONTEND_URL).
// Auth is a Bearer session token, never cookies, so credentials stay off.
return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter([env('FRONTEND_URL')])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept'],

    'exposed_headers' => ['X-Shopify-Retry-Invalid-Session-Request'],

    'max_age' => 600,

    'supports_credentials' => false,

];
