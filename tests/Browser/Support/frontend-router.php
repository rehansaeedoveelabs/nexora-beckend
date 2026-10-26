<?php

/**
 * Router for `php -S` serving Nexora-front-end during Dusk E2E tests.
 *
 * Serves the real frontend files unchanged, except:
 *  - App Bridge (only exists inside the Shopify admin iframe) is replaced by a stub that
 *    issues session tokens signed with the TEST secret and records toasts on the page.
 *  - config.js points the frontend at the E2E API server.
 *
 * Test-only: lives in the backend's tests/ folder and is never deployed.
 */

use Firebase\JWT\JWT;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$frontend = getenv('NEXORA_FRONTEND_ROOT');

if ($path === '/__e2e/token') {
    $shop = getenv('NEXORA_E2E_SHOP');
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

if ($path === '/src/config.js') {
    header('Content-Type: text/javascript');
    echo "export const API_BASE_URL = '".getenv('NEXORA_E2E_API')."';\n";

    return true;
}

if (str_starts_with($path, '/src/') && is_file($frontend.$path)) {
    $types = ['js' => 'text/javascript', 'css' => 'text/css'];
    header('Content-Type: '.($types[pathinfo($path, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
    readfile($frontend.$path);

    return true;
}

// Every other path is an app route: serve index.html (SPA fallback) with the App Bridge stub.
$stub = <<<'HTML'
<style>s-app-nav { display: none; }</style>
<script>
  window.__toasts = [];
  window.shopify = {
    idToken: async () => (await fetch('/__e2e/token')).text(),
    toast: {
      show(message, options = {}) {
        window.__toasts.push({ message, isError: !!options.isError });
        const log = document.getElementById('e2e-toasts') || document.body.appendChild(Object.assign(document.createElement('div'), { id: 'e2e-toasts' }));
        log.appendChild(document.createElement('p')).textContent = message;
      },
    },
  };
</script>
HTML;

$html = file_get_contents($frontend.'/index.html');
$html = preg_replace('#<script src="https://cdn\.shopify\.com/shopifycloud/app-bridge\.js"></script>#', $stub, $html, 1, $replaced);

if ($replaced !== 1) {
    http_response_code(500);
    echo 'E2E router: App Bridge script tag not found in index.html';

    return true;
}

header('Content-Type: text/html; charset=utf-8');
echo $html;

return true;
