<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desk_history_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_id')->constrained('crm_connections')->cascadeOnDelete();
            $table->unsignedBigInteger('city_id')->index();
            $table->unsignedSmallInteger('days');
            $table->date('period_from');
            $table->date('period_to');
            $table->unsignedInteger('next_page')->default(1);
            $table->enum('state', ['queued', 'running', 'completed', 'failed'])->default('queued')->index();
            $table->boolean('automatic')->default(false)->index();
            $table->unsignedInteger('pages_processed')->default(0);
            $table->unsignedInteger('orders_upserted')->default(0);
            $table->timestamp('last_processed_at')->nullable()->index();
            $table->text('error')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['city_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desk_history_sync_runs');
    }
};
