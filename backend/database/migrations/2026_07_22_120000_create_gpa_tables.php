<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'display_name')) {
                $table->string('display_name', 80)->nullable()->after('name');
            }
            if (! Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('remember_token');
            }
        });

        Schema::create('search_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('query', 200);
            $table->unsignedBigInteger('appid')->nullable()->index();
            $table->string('game_name', 200)->nullable();
            $table->string('header_image', 500)->nullable();
            $table->decimal('steam_price_rub', 12, 2)->nullable();
            $table->decimal('plati_min_rub', 12, 2)->nullable();
            $table->decimal('ggsel_min_rub', 12, 2)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('appid');
            $table->string('game_name', 200);
            $table->string('header_image', 500)->nullable();
            $table->text('notes')->nullable();
            $table->decimal('target_price_rub', 12, 2)->nullable();
            $table->decimal('last_steam_price_rub', 12, 2)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'appid']);
        });

        Schema::create('price_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('appid')->nullable()->index();
            $table->decimal('steam_price_rub', 12, 2)->nullable();
            $table->decimal('market_min_rub', 12, 2)->nullable();
            $table->string('source_query', 200)->default('');
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'appid', 'created_at']);
        });

        Schema::create('daily_search_quotas', function (Blueprint $table) {
            $table->id();
            $table->string('quota_key', 120);
            $table->string('day', 10);
            $table->unsignedInteger('count')->default(0);
            $table->timestamps();
            $table->unique(['quota_key', 'day']);
            $table->index('day');
        });

        Schema::create('partner_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('marketplace', 40);
            $table->string('url', 1000);
            $table->unsignedBigInteger('appid')->nullable();
            $table->string('query', 200)->nullable();
            $table->decimal('price_rub', 12, 2)->nullable();
            $table->string('client_ip', 64)->nullable();
            $table->timestamps();
            $table->index(['marketplace', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_clicks');
        Schema::dropIfExists('daily_search_quotas');
        Schema::dropIfExists('price_snapshots');
        Schema::dropIfExists('favorites');
        Schema::dropIfExists('search_histories');
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'display_name')) {
                $table->dropColumn('display_name');
            }
            if (Schema::hasColumn('users', 'last_login_at')) {
                $table->dropColumn('last_login_at');
            }
        });
    }
};
