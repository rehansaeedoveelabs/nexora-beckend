<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class EnsureE2eDatabase
{
    public function handle(Request $request, Closure $next): Response
    {
        if (getenv('NEXORA_E2E_GUARD') !== '1') {
            return $next($request);
        }

        $connectionName = config('database.default');
        $connection = DB::connection($connectionName);
        $configuredDatabase = $connection->getDatabaseName();
        $expectedDatabase = base_path('storage/framework/testing/nexora-e2e.sqlite');
        $actualDatabase = collect($connection->select('PRAGMA database_list'))
            ->firstWhere('name', 'main')->file ?? null;

        if (
            app()->environment() !== 'testing'
            || $connectionName !== 'sqlite'
            || is_link($expectedDatabase)
            || realpath($configuredDatabase) !== $expectedDatabase
            || realpath($actualDatabase) !== $expectedDatabase
        ) {
            throw new RuntimeException(
                'E2E API safety check failed: request blocked because the isolated SQLite test file could not be verified.'
            );
        }

        return $next($request);
    }
}
