<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders_cache', function (Blueprint $table) {
            if (! Schema::hasColumn('orders_cache', 'customer_external_id')) {
                $table->string('customer_external_id', 32)->nullable()->after('client_age');
            }
            if (! Schema::hasColumn('orders_cache', 'address_office')) {
                $table->string('address_office', 500)->nullable()->after('address_norm');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders_cache', function (Blueprint $table) {
            foreach (['address_office', 'customer_external_id'] as $col) {
                if (Schema::hasColumn('orders_cache', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
