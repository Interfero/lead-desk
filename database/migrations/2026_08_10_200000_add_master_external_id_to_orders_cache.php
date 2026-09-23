<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders_cache', function (Blueprint $table) {
            if (! Schema::hasColumn('orders_cache', 'master_external_id')) {
                $table->string('master_external_id', 64)->nullable()->after('master_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders_cache', function (Blueprint $table) {
            if (Schema::hasColumn('orders_cache', 'master_external_id')) {
                $table->dropColumn('master_external_id');
            }
        });
    }
};
