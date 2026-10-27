<?php

return [

    // Public app identifier (client_id). Safe to expose to the frontend.
    'api_key' => env('SHOPIFY_API_KEY'),

    // Client secret. Signs session tokens + webhooks. Never leaves the backend.
    'api_secret' => env('SHOPIFY_API_SECRET'),

    // Pinned Admin GraphQL version. Bump deliberately, never "latest".
    'api_version' => env('SHOPIFY_API_VERSION', '2027-01'),

    // Origin of the embedded frontend (the app URL). Only origin allowed by CORS.
    'frontend_url' => env('FRONTEND_URL'),

    // Refresh expiring offline tokens this many seconds before they expire.
    'token_refresh_margin' => 300,

    // Clock skew tolerated when verifying session tokens.
    'jwt_leeway' => 10,

];
