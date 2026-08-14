<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_price_observations', function (Blueprint $table): void {
            $table->unsignedTinyInteger('discount_percent')->nullable()->after('min_price_rub');
        });
        DB::statement('ALTER TABLE game_price_observations ADD CONSTRAINT game_price_observations_discount_percent_check CHECK (discount_percent BETWEEN 0 AND 100)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE game_price_observations DROP CONSTRAINT IF EXISTS game_price_observations_discount_percent_check');
        Schema::table('game_price_observations', function (Blueprint $table): void {
            $table->dropColumn('discount_percent');
        });
    }
};
