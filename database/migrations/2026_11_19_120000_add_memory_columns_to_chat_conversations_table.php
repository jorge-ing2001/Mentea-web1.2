<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->string('title')->nullable()->after('session_id');
            $table->text('summary')->nullable()->after('title');
            $table->json('context_metadata')->nullable()->after('summary');
            $table->timestamp('last_message_at')->nullable()->after('last_activity_at');

            $table->index(['user_id', 'status', 'last_message_at'], 'chat_conversations_user_status_last_message_index');
        });
    }

    public function down(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->dropIndex('chat_conversations_user_status_last_message_index');
            $table->dropColumn(['title', 'summary', 'context_metadata', 'last_message_at']);
        });
    }
};
