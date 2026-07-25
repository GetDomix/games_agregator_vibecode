<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('favorite_alerts', function (Blueprint $table) {
            $table->id(); $table->foreignId('favorite_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('condition_type', 30)->default('target_price'); $table->decimal('target_value', 12, 2)->nullable();
            $table->string('status', 20)->default('active'); $table->timestamp('triggered_at')->nullable(); $table->timestamps();
        });
        Schema::create('favorite_alert_scopes', function (Blueprint $table) {
            $table->id(); $table->foreignId('favorite_alert_id')->constrained()->cascadeOnDelete();
            $table->string('source', 20); $table->string('offer_kind', 20); $table->timestamps();
            $table->unique(['favorite_alert_id', 'source', 'offer_kind']);
        });
        DB::table('favorites')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $fav) {
                $id = DB::table('favorite_alerts')->insertGetId(['favorite_id' => $fav->id, 'condition_type' => 'target_price', 'target_value' => $fav->target_price_rub, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
                DB::table('favorite_alert_scopes')->insert(['favorite_alert_id' => $id, 'source' => 'steam', 'offer_kind' => 'official', 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }
    public function down(): void { Schema::dropIfExists('favorite_alert_scopes'); Schema::dropIfExists('favorite_alerts'); }
};
