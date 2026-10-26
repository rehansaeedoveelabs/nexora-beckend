<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * The shop resolved from the verified session token (see VerifyShopifySessionToken).
     */
    protected function shop(Request $request): Shop
    {
        return $request->attributes->get('shop');
    }
}
