<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->string('client_message_id')->nullable()->after('meta');

            $table->unique(['chat_conversation_id', 'client_message_id'], 'chat_messages_conversation_client_message_unique');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropUnique('chat_messages_conversation_client_message_unique');
            $table->dropColumn('client_message_id');
        });
    }
};
