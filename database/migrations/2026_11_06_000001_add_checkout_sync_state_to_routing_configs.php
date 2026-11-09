<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routing_configs', function (Blueprint $table) {
            $table->string('checkout_sync_status')->default('not_synced');
            $table->text('checkout_sync_error')->nullable();
            $table->timestamp('checkout_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('routing_configs', function (Blueprint $table) {
            $table->dropColumn(['checkout_sync_status', 'checkout_sync_error', 'checkout_synced_at']);
        });
    }
};
