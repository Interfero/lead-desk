<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders_cache', function (Blueprint $table) {
            if (! Schema::hasColumn('orders_cache', 'needs_feedback')) {
                $table->boolean('needs_feedback')->default(false)->after('is_noncore');
            }
            if (! Schema::hasColumn('orders_cache', 'client_history')) {
                $table->json('client_history')->nullable()->after('documents');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders_cache', function (Blueprint $table) {
            foreach (['needs_feedback', 'client_history'] as $col) {
                if (Schema::hasColumn('orders_cache', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
