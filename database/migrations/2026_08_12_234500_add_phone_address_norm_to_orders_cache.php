<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders_cache', function (Blueprint $table) {
            if (! Schema::hasColumn('orders_cache', 'phone_norm')) {
                $table->string('phone_norm', 20)->nullable()->after('phone')->index();
            }
            if (! Schema::hasColumn('orders_cache', 'address_norm')) {
                $table->string('address_norm', 500)->nullable()->after('address')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders_cache', function (Blueprint $table) {
            foreach (['phone_norm', 'address_norm'] as $col) {
                if (Schema::hasColumn('orders_cache', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
