<?php

return [

    // Public app identifier (client_id). Safe to expose to the frontend.
    'api_key' => env('SHOPIFY_API_KEY'),

    // Client secret. Signs session tokens + webhooks. Never leaves the backend.
    'api_secret' => env('SHOPIFY_API_SECRET'),

    // Pinned Admin GraphQL version. Bump deliberately, never "latest".
    'api_version' => env('SHOPIFY_API_VERSION', '2026-10'),

    // Must match the deployed fulfillment-constraints Function extension handle.
    'fulfillment_constraint_function_handle' => env(
        'SHOPIFY_FULFILLMENT_CONSTRAINT_FUNCTION_HANDLE',
        'nexora-fulfillment-constraints',
    ),

    // Origin of the embedded frontend (the app URL). Only origin allowed by CORS.
    'frontend_url' => env('FRONTEND_URL'),

    // Refresh expiring offline tokens this many seconds before they expire.
    'token_refresh_margin' => 300,

    // Clock skew tolerated when verifying session tokens.
    'jwt_leeway' => 10,

    /*
     * Shopify Flow trigger handles, keyed by the symbolic name the backend emits.
     * Each handle must match the `handle` declared in the Flow trigger extension's
     * shopify.extension.toml, and the payload keys the emitter sends must match that
     * extension's field schema. A key left null (or absent) is treated as "not
     * configured": the emitter skips it silently so an undeployed trigger never
     * blocks a fulfillment move. Add 'order_at_capacity' / 'safety_stock_breached'
     * here when those extensions exist.
     */
    'flow_triggers' => [
        'order_rerouted' => env('SHOPIFY_FLOW_TRIGGER_ORDER_REROUTED'),
    ],

];
