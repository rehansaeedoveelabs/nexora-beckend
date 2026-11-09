<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Savings\SavingsReadiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavingsController extends Controller
{
    public function __construct(private readonly SavingsReadiness $readiness) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->readiness->forShop($this->shop($request)),
        ]);
    }
}
