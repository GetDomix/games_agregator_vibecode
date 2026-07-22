<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'telegram_chat_id')) {
                $table->string('telegram_chat_id', 32)->nullable()->unique()->after('is_admin');
            }
            if (! Schema::hasColumn('users', 'telegram_username')) {
                $table->string('telegram_username', 64)->nullable()->after('telegram_chat_id');
            }
            if (! Schema::hasColumn('users', 'telegram_linked_at')) {
                $table->timestamp('telegram_linked_at')->nullable()->after('telegram_username');
            }
            if (! Schema::hasColumn('users', 'radar_enabled')) {
                $table->boolean('radar_enabled')->default(true)->after('telegram_linked_at');
            }
        });

        Schema::table('favorites', function (Blueprint $table) {
            if (! Schema::hasColumn('favorites', 'radar_enabled')) {
                $table->boolean('radar_enabled')->default(true)->after('last_steam_price_rub');
            }
            if (! Schema::hasColumn('favorites', 'last_notified_price_rub')) {
                $table->decimal('last_notified_price_rub', 12, 2)->nullable()->after('radar_enabled');
            }
            if (! Schema::hasColumn('favorites', 'last_notified_at')) {
                $table->timestamp('last_notified_at')->nullable()->after('last_notified_price_rub');
            }
        });

        Schema::create('telegram_link_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code', 16)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('radar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('favorite_id')->nullable()->constrained('favorites')->nullOnDelete();
            $table->unsignedBigInteger('appid');
            $table->string('game_name', 200);
            $table->string('kind', 32); // target_hit | steam_drop
            $table->decimal('old_price_rub', 12, 2)->nullable();
            $table->decimal('new_price_rub', 12, 2)->nullable();
            $table->decimal('target_price_rub', 12, 2)->nullable();
            $table->boolean('notified')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radar_events');
        Schema::dropIfExists('telegram_link_codes');
        Schema::table('favorites', function (Blueprint $table) {
            foreach (['radar_enabled', 'last_notified_price_rub', 'last_notified_at'] as $col) {
                if (Schema::hasColumn('favorites', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::table('users', function (Blueprint $table) {
            foreach (['telegram_chat_id', 'telegram_username', 'telegram_linked_at', 'radar_enabled'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
