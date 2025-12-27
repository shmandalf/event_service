<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('event_type', 50)->index();
            $table->timestamp('timestamp')->index();
            $table->smallInteger('priority')->default(0)->index();
            $table->json('payload');
            $table->json('metadata')->nullable();
            $table->timestamp('processed_at')->nullable()->index();
            $table->string('status', 20)->default('pending')->index();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->integer('retry_count')->default(0);
            $table->text('last_error')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'timestamp']);
            $table->index(['event_type', 'processed_at']);
            $table->index(['status', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
