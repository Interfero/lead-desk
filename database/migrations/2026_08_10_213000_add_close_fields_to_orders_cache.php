<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders_cache', function (Blueprint $table) {
            if (! Schema::hasColumn('orders_cache', 'prepayment')) {
                $table->unsignedInteger('prepayment')->nullable()->after('parts_amount');
            }
            if (! Schema::hasColumn('orders_cache', 'with_bso')) {
                $table->unsignedTinyInteger('with_bso')->nullable()->after('prepayment');
            }
            if (! Schema::hasColumn('orders_cache', 'with_zip')) {
                $table->unsignedTinyInteger('with_zip')->nullable()->after('with_bso');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders_cache', function (Blueprint $table) {
            foreach (['prepayment', 'with_bso', 'with_zip'] as $col) {
                if (Schema::hasColumn('orders_cache', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
