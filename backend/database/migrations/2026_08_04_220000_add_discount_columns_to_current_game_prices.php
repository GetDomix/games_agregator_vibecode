<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('current_game_prices', function (Blueprint $table) {
            $table->unsignedTinyInteger('discount_percent')->nullable();
            $table->decimal('price_initial_rub', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('current_game_prices', function (Blueprint $table) {
            $table->dropColumn(['discount_percent', 'price_initial_rub']);
        });
    }
};
