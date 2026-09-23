<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders_cache', function (Blueprint $table) {
            if (! Schema::hasColumn('orders_cache', 'rk')) {
                $table->string('rk')->nullable()->after('city_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders_cache', function (Blueprint $table) {
            if (Schema::hasColumn('orders_cache', 'rk')) {
                $table->dropColumn('rk');
            }
        });
    }
};
