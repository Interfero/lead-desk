<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders_cache', function (Blueprint $table) {
            $table->string('order_core', 16)->nullable()->after('order_type');
            $table->boolean('is_long_trip')->default(false)->after('is_noncore');
            $table->boolean('is_satellite')->default(false)->after('is_long_trip');
            $table->boolean('is_partner_order')->default(false)->after('is_satellite');
        });
    }

    public function down(): void
    {
        Schema::table('orders_cache', function (Blueprint $table) {
            $table->dropColumn(['order_core', 'is_long_trip', 'is_satellite', 'is_partner_order']);
        });
    }
};
