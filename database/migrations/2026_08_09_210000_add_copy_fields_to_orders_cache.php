<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders_cache', function (Blueprint $table) {
            $table->string('client_age', 32)->nullable()->after('client_name');
            $table->boolean('is_noncore')->default(false)->after('order_type');
        });
    }

    public function down(): void
    {
        Schema::table('orders_cache', function (Blueprint $table) {
            $table->dropColumn(['client_age', 'is_noncore']);
        });
    }
};
