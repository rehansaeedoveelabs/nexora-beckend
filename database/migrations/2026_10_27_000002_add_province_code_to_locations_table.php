<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Routing compares against the customer's province CODE (e.g. "ON"); `province` holds the name.
        Schema::table('locations', function (Blueprint $table) {
            $table->string('province_code', 10)->nullable()->after('province');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('province_code');
        });
    }
};
