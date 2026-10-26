<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotency ledger: Shopify retries deliveries, X-Shopify-Webhook-Id stays the same.
        Schema::create('webhook_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('webhook_id')->unique();
            $table->string('shop_domain');
            $table->string('topic', 100);
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();

            $table->index(['shop_domain', 'topic']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_receipts');
    }
};
