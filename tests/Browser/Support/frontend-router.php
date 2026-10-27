<?php

/**
 * Router for `php -S` serving the BUILT Nexora frontend (Nexora-front-end/build-e2e/client)
 * during Dusk E2E tests.
 *
 * Serves the real build output unchanged, except: App Bridge (which only works inside the
 * Shopify admin iframe) is pointed at a local stub that issues session tokens signed with the
 * TEST secret and records toasts on the page. Changing only the script's `src` keeps React's
 * hydration intact (production React doesn't re-render for attribute differences).
 *
 * Test-only: lives in the backend's tests/ folder and is never deployed.
 */

use Firebase\JWT\JWT;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$build = rtrim((string) getenv('NEXORA_FRONTEND_ROOT'), '/').'/build-e2e/client';

if ($path === '/__e2e/token') {
    $shop = getenv('NEXORA_E2E_SHOP');
    if (! $shop || ! getenv('SHOPIFY_API_KEY') || ! getenv('SHOPIFY_API_SECRET')) {
        // Fail loudly: a missing test secret otherwise surfaces as a confusing network error.
        http_response_code(500);
        error_log('E2E router: NEXORA_E2E_SHOP / SHOPIFY_API_KEY / SHOPIFY_API_SECRET not set');

        return true;
    }

    $now = time();
    header('Content-Type: text/plain');
    header('Cache-Control: no-store');
    echo JWT::encode([
        'iss' => "https://{$shop}/admin",
        'dest' => "https://{$shop}",
        'aud' => getenv('SHOPIFY_API_KEY'),
        'sub' => '1',
        'exp' => $now + 60,
        'nbf' => $now - 5,
        'iat' => $now - 5,
        'jti' => bin2hex(random_bytes(8)),
    ], getenv('SHOPIFY_API_SECRET'), 'HS256');

    return true;
}

if ($path === '/__e2e/app-bridge-stub.js') {
    header('Content-Type: text/javascript');
    echo <<<'JS'
        // Stand-in for App Bridge outside the Shopify admin.
        // Record page errors so failing E2E runs can show the real cause (window.__errors).
        window.__errors = [];
        addEventListener('error', (e) => window.__errors.push(String(e.message || e.error)));
        addEventListener('unhandledrejection', (e) => window.__errors.push('Unhandled: ' + String(e.reason?.stack || e.reason)));
        const consoleError = console.error.bind(console);
        console.error = (...args) => { window.__errors.push(args.map(String).join(' ').slice(0, 500)); consoleError(...args); };
        document.head.appendChild(Object.assign(document.createElement('style'), { textContent: 's-app-nav { display: none; }' }));
        window.__toasts = [];
        window.shopify = {
          idToken: async () => (await fetch('/__e2e/token')).text(),
          // Minimal stand-in for App Bridge's contextual save bar. The <ui-save-bar> element isn't
          // registered here, so its <button> children render inline and are clickable by tests;
          // these methods just track visibility.
          saveBar: {
            visible: false,
            show() { this.visible = true; },
            hide() { this.visible = false; },
            toggle() { this.visible = !this.visible; },
            leaveConfirmation: async () => {},
          },
          toast: {
            show(message, options = {}) {
              window.__toasts.push({ message, isError: !!options.isError });
              const log = document.getElementById('e2e-toasts')
                || document.body.appendChild(Object.assign(document.createElement('div'), { id: 'e2e-toasts' }));
              log.appendChild(document.createElement('p')).textContent = message;
            },
          },
        };
        JS;

    return true;
}

if ($path !== '/' && is_file($build.$path) && ! str_contains($path, '..')) {
    $types = ['js' => 'text/javascript', 'css' => 'text/css', 'json' => 'application/json', 'svg' => 'image/svg+xml'];
    header('Content-Type: '.($types[pathinfo($path, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
    readfile($build.$path);

    return true;
}

// Every other path is an app route: serve the SPA's index.html with App Bridge pointed at the stub.
$indexFile = $build.'/index.html';
if (! is_file($indexFile)) {
    http_response_code(500);
    echo "E2E router: {$indexFile} not found. The frontend build step did not run.";

    return true;
}

$html = str_replace(
    'src="https://cdn.shopify.com/shopifycloud/app-bridge.js"',
    'src="/__e2e/app-bridge-stub.js"',
    file_get_contents($indexFile),
    $replaced,
);

if ($replaced !== 1) {
    http_response_code(500);
    echo 'E2E router: App Bridge script tag not found exactly once in index.html';

    return true;
}

header('Content-Type: text/html; charset=utf-8');
echo $html;

return true;
