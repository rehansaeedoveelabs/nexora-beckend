<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Setup\SetupProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    /**
     * First call the frontend makes: authenticates (and installs on first open) and returns
     * everything the shell needs to render.
     */
    public function show(Request $request, SetupProgress $setup): JsonResponse
    {
        $shop = $this->shop($request);

        return response()->json([
            'data' => [
                'shop' => [
                    'domain' => $shop->domain,
                    'name' => $shop->name,
                    'plan' => $shop->plan_name,
                    'currency' => $shop->currency,
                    'timezone' => $shop->timezone,
                    'syncing' => $shop->locations_synced_at === null,
                ],
                'setup' => $setup->for($shop),
            ],
        ]);
    }

    public function setup(Request $request, SetupProgress $setup): JsonResponse
    {
        return response()->json(['data' => $setup->for($this->shop($request))]);
    }
}
