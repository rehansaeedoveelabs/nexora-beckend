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
        //  - `compiled`: the same rules resolved to Shopify location GIDs. This exact JSON is what the
        //    simulator evaluates and what gets written to the app metafield for the Shopify Functions,
        //    so both apply the same eligibility rules and ranking. (Shopify makes the final pick among
        //    eligible locations; the simulator approximates that step.)
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
