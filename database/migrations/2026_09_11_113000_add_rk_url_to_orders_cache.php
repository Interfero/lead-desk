<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders_cache', function (Blueprint $table) {
            if (! Schema::hasColumn('orders_cache', 'rk_url')) {
                $table->string('rk_url', 2048)->nullable()->after('rk');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders_cache', function (Blueprint $table) {
            if (Schema::hasColumn('orders_cache', 'rk_url')) {
                $table->dropColumn('rk_url');
            }
        });
    }
};
