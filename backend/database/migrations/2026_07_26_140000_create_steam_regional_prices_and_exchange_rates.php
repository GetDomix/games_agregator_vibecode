<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('currency', 3)->unique();
            $table->decimal('rub_per_unit', 16, 6);
            $table->timestamp('observed_at');
            $table->timestamps();
        });

        Schema::create('steam_regional_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->cascadeOnDelete();
            $table->string('region', 2);
            $table->string('currency', 3);
            $table->decimal('price_amount', 12, 2);
            $table->decimal('price_rub', 12, 2)->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();
            $table->unique(['game_id', 'region']);
            $table->index(['currency', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('steam_regional_prices');
        Schema::dropIfExists('exchange_rates');
    }
};
