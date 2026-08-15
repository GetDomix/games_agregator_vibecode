<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('current_game_prices', function (Blueprint $table) {
            $table->json('currency_prices')->nullable()->after('avg_price_rub');
        });
    }

    public function down(): void
    {
        Schema::table('current_game_prices', function (Blueprint $table) {
            $table->dropColumn('currency_prices');
        });
    }
};
