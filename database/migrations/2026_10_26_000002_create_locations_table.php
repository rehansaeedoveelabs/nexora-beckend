<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();

            // Mirrored from Shopify (Shopify is the source of truth for these).
            $table->string('shopify_id');
            $table->string('name');
            $table->string('address1')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('zip', 32)->nullable();
            $table->string('formatted_address', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('ships')->default(false);
            $table->boolean('pickup')->default(false);
            $table->string('managed_by_app')->nullable();

            // Owned by Nexora (merchant configuration).
            $table->string('type', 32)->nullable();
            $table->json('tags')->nullable();
            $table->unsignedInteger('daily_order_cap')->nullable();
            $table->unsignedInteger('backlog_cap')->nullable();

            $table->timestamps();

            $table->unique(['shop_id', 'shopify_id']);
            $table->index(['shop_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
