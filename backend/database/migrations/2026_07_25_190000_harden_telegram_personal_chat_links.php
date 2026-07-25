<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Telegram group and supergroup identifiers are negative. Their owner
        // cannot be inferred safely, so retain account data but stop delivery.
        DB::table('users')
            ->where('telegram_chat_id', 'like', '-%')
            ->update([
                'telegram_chat_id' => null,
                'telegram_username' => null,
                'telegram_linked_at' => null,
                'radar_enabled' => false,
            ]);
    }

    public function down(): void
    {
        // Unsafe group links are intentionally not restored.
    }
};
