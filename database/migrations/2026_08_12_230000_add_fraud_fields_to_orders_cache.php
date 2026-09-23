<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders_cache', function (Blueprint $table) {
            if (! Schema::hasColumn('orders_cache', 'fraud_status')) {
                $table->string('fraud_status', 16)->nullable()->after('row_highlight');
            }
            if (! Schema::hasColumn('orders_cache', 'fraud_note')) {
                $table->string('fraud_note', 500)->nullable()->after('fraud_status');
            }
            if (! Schema::hasColumn('orders_cache', 'fraud_checked_at')) {
                $table->timestamp('fraud_checked_at')->nullable()->after('fraud_note');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders_cache', function (Blueprint $table) {
            foreach (['fraud_checked_at', 'fraud_note', 'fraud_status'] as $col) {
                if (Schema::hasColumn('orders_cache', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
