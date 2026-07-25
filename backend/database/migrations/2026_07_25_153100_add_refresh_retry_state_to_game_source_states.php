<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_source_states', function (Blueprint $table) {
            $table->unsignedInteger('consecutive_failures')->default(0)->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('game_source_states', function (Blueprint $table) {
            $table->dropColumn('consecutive_failures');
        });
    }
};
