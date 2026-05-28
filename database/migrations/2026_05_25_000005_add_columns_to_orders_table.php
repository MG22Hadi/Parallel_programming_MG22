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
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('payment_intent_id')->nullable()->after('user_id')->constrained('payment_intents')->nullOnDelete();
            $table->string('transaction_phase', 32)->nullable()->after('status');
            $table->string('idempotency_key', 128)->nullable()->after('transaction_phase');
            $table->string('trace_id', 128)->nullable()->after('idempotency_key');
            $table->string('correlation_id', 128)->nullable()->after('trace_id');
            $table->string('failure_code', 64)->nullable()->after('correlation_id');
            $table->text('failure_reason')->nullable()->after('failure_code');
            $table->unsignedInteger('retry_count')->default(0)->after('failure_reason');
            $table->timestamp('next_retry_at')->nullable()->after('retry_count');
            $table->timestamp('expires_at')->nullable()->after('next_retry_at');
            $table->timestamp('processed_at')->nullable()->after('expires_at');
            $table->timestamp('completed_at')->nullable()->after('processed_at');
            $table->timestamp('failed_at')->nullable()->after('completed_at');

            $table->index('payment_intent_id');
            $table->index('idempotency_key');
            $table->index('transaction_phase');
            $table->index('next_retry_at');
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['payment_intent_id']);
            $table->dropColumn([
                'payment_intent_id',
                'transaction_phase',
                'idempotency_key',
                'trace_id',
                'correlation_id',
                'failure_code',
                'failure_reason',
                'retry_count',
                'next_retry_at',
                'expires_at',
                'processed_at',
                'completed_at',
                'failed_at',
            ]);
        });
    }
};
