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
        Schema::table('worklogs', function (Blueprint $table) {
            // Add user tracking
            $table->foreignId('user_id')->nullable()->after('ticket_id')
                ->constrained('users')->nullOnDelete();
            
            // Add status tracking
            $table->string('status', 20)->default('completed')->after('notes')
                ->comment('Status: in_progress, completed, failed');
            
            // Add sync tracking
            $table->timestamp('synced_at')->nullable()->after('status')
                ->comment('When worklog was synced to ticket provider');
            $table->string('sync_status', 20)->nullable()->after('synced_at')
                ->comment('Sync status: success, failed');
            $table->text('sync_error')->nullable()->after('sync_status')
                ->comment('Error message if sync failed');
            
            // Add indexes
            $table->index(['status', 'synced_at']);
            $table->index('phase');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('worklogs', function (Blueprint $table) {
            $table->dropIndex(['status', 'synced_at']);
            $table->dropIndex(['phase']);
            
            $table->dropForeign(['user_id']);
            $table->dropColumn([
                'user_id',
                'status',
                'synced_at',
                'sync_status',
                'sync_error',
            ]);
        });
    }
};