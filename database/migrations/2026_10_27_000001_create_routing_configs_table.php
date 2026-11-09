<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One routing configuration per shop.
        //  - `settings`: what the merchant edits (local location ids, rule sentences).
        //  - `compiled`: rules resolved to Shopify location GIDs for the backend router and simulator.
        //    Checkout uses a separate product-ID config built from this data because Functions
        //    cannot read product tags. Shopify chooses the final location among eligible locations.
        Schema::create('routing_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('settings');
            $table->json('compiled');
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routing_configs');
    }
};
