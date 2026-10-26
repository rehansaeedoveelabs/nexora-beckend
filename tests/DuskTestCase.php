<?php

namespace Tests;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Support\Collection;
use Laravel\Dusk\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\AfterClass;
use PHPUnit\Framework\Attributes\BeforeClass;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * E2E harness: boots the real backend API and the real frontend (two `php -S` servers)
 * sharing the test database, then drives Chrome against the frontend.
 */
abstract class DuskTestCase extends BaseTestCase
{
    protected const API_PORT = 8010;
    protected const FRONTEND_PORT = 8011;
    protected const SHOP = 'e2e.myshopify.com';

    /** @var Process[] */
    private static array $servers = [];

    #[BeforeClass]
    public static function prepare(): void
    {
        if (! static::runningInSail()) {
            static::startChromeDriver(['--port=9515']);
        }

        $backend = dirname(__DIR__);
        $frontendUrl = 'http://127.0.0.1:'.static::FRONTEND_PORT;

        $env = [
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'false',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'FRONTEND_URL' => $frontendUrl,
            'NEXORA_FRONTEND_ROOT' => dirname($backend).'/Nexora-front-end',
            'NEXORA_E2E_API' => 'http://127.0.0.1:'.static::API_PORT.'/api',
            'NEXORA_E2E_SHOP' => static::SHOP,
        ];
        // DB + Shopify test credentials come from phpunit.xml (or the shell) so both servers
        // and the test process share the same database.
        foreach (['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
            'SHOPIFY_API_KEY', 'SHOPIFY_API_SECRET', 'SHOPIFY_API_VERSION', 'APP_KEY'] as $key) {
            if (($value = getenv($key)) !== false) {
                $env[$key] = $value;
            }
        }

        self::$servers = [
            self::serve(['php', '-S', '127.0.0.1:'.static::API_PORT, '-t', 'public', 'public/index.php'], $backend, $env, static::API_PORT),
            self::serve(['php', '-S', '127.0.0.1:'.static::FRONTEND_PORT, 'tests/Browser/Support/frontend-router.php'], $backend, $env, static::FRONTEND_PORT),
        ];
    }

    #[AfterClass]
    public static function stopServers(): void
    {
        foreach (self::$servers as $server) {
            $server->stop(1);
        }
        self::$servers = [];
    }

    private static function serve(array $command, string $cwd, array $env, int $port): Process
    {
        $process = new Process($command, $cwd, $env);
        $process->start();

        for ($i = 0; $i < 50; $i++) {
            if (@fsockopen('127.0.0.1', $port)) {
                return $process;
            }
            usleep(100_000);
        }

        throw new RuntimeException("E2E server on port {$port} did not start: ".$process->getErrorOutput());
    }

    protected function baseUrl(): string
    {
        return 'http://127.0.0.1:'.static::FRONTEND_PORT;
    }

    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments(collect([
            $this->shouldStartMaximized() ? '--start-maximized' : '--window-size=1440,1000',
            '--disable-search-engine-choice-screen',
            '--disable-smooth-scrolling',
        ])->unless($this->hasHeadlessDisabled(), function (Collection $items) {
            return $items->merge([
                '--disable-gpu',
                '--headless=new',
            ]);
        })->all());

        return RemoteWebDriver::create(
            $_ENV['DUSK_DRIVER_URL'] ?? env('DUSK_DRIVER_URL') ?? 'http://localhost:9515',
            DesiredCapabilities::chrome()->setCapability(
                ChromeOptions::CAPABILITY, $options
            )
        );
    }
}
