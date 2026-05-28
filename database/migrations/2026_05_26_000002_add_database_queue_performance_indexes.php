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
        Schema::table('jobs', function (Blueprint $table) {
            $table->index(['queue', 'reserved_at']);
            $table->index(['queue', 'available_at']);
            $table->index('reserved_at');
            $table->index('available_at');
            $table->index('created_at');
        });

        Schema::table('failed_jobs', function (Blueprint $table) {
            $table->string('queue')->change();
            $table->index('queue');
            $table->index('failed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropIndex(['jobs_queue_reserved_at_index']);
            $table->dropIndex(['jobs_queue_available_at_index']);
            $table->dropIndex(['jobs_reserved_at_index']);
            $table->dropIndex(['jobs_available_at_index']);
            $table->dropIndex(['jobs_created_at_index']);
        });

        Schema::table('failed_jobs', function (Blueprint $table) {
            $table->dropIndex(['failed_jobs_queue_index']);
            $table->dropIndex(['failed_jobs_failed_at_index']);
            $table->text('queue')->change();
        });
    }
};
