<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_connections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 64);
            $table->string('base_url')->nullable();
            $table->string('login')->nullable();
            $table->text('password')->nullable();
            $table->enum('status', ['active', 'inactive', 'error'])->default('active');
            $table->timestamp('last_sync_at')->nullable();
            $table->unsignedInteger('sync_interval')->default(60);
            $table->unsignedInteger('timeout')->default(15);
            $table->json('config')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('desk_status_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_id')->constrained('crm_connections')->cascadeOnDelete();
            $table->string('crm_status_code', 64);
            $table->string('unified_status', 32);
            $table->boolean('is_closed')->default(false);
            $table->unsignedSmallInteger('display_order')->default(100);
            $table->timestamps();
            $table->unique(['crm_id', 'crm_status_code']);
        });

        Schema::create('orders_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_id')->constrained('crm_connections')->cascadeOnDelete();
            $table->string('external_id', 64);
            $table->unsignedBigInteger('city_id')->nullable()->index();
            $table->string('city_name')->nullable();
            $table->string('status', 32)->index();
            $table->string('raw_status', 64)->nullable();
            $table->string('client_name')->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('address')->nullable();
            $table->text('description')->nullable();
            $table->string('master_name')->nullable();
            $table->unsignedInteger('total_amount')->nullable();
            $table->unsignedInteger('paid_amount')->nullable();
            $table->unsignedInteger('parts_amount')->nullable();
            $table->timestamp('created_at_local')->nullable()->index();
            $table->timestamp('call_at_local')->nullable();
            $table->string('timezone', 64)->nullable();
            $table->timestamp('updated_at_local')->nullable();
            $table->string('order_type', 32)->nullable();
            $table->string('gm_status')->nullable();
            $table->text('comments')->nullable();
            $table->json('documents')->nullable();
            $table->unsignedSmallInteger('priority')->default(0);
            $table->string('row_highlight', 32)->nullable();
            $table->string('hash', 64)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['crm_id', 'external_id']);
        });

        Schema::create('desk_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_id')->nullable()->constrained('crm_connections')->nullOnDelete();
            $table->string('level', 16)->default('info')->index();
            $table->string('action', 64)->nullable();
            $table->string('external_id', 64)->nullable();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('desk_stats', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desk_stats');
        Schema::dropIfExists('desk_logs');
        Schema::dropIfExists('orders_cache');
        Schema::dropIfExists('desk_status_mappings');
        Schema::dropIfExists('crm_connections');
    }
};
