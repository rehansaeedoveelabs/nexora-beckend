<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ids captured after `shopify app deploy`: the deployed constraints Function and the
        // fulfillment constraint rule that binds it (whose metafield holds our config).
        Schema::table('shops', function (Blueprint $table) {
            $table->string('fulfillment_function_id')->nullable()->after('scopes');
            $table->string('fulfillment_constraint_rule_id')->nullable()->after('fulfillment_function_id');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['fulfillment_function_id', 'fulfillment_constraint_rule_id']);
        });
    }
};
