<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('favorite_alerts', function (Blueprint $table) {
            $table->unsignedInteger('cycle')->default(0)->after('status');
        });

        Schema::create('alert_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('favorite_alert_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('alert_cycle');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('favorite_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('source', 20);
            $table->string('offer_kind', 20);
            $table->decimal('offer_price_rub', 12, 2);
            $table->string('offer_title', 500)->nullable();
            $table->string('offer_url', 1000)->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->unique(['favorite_alert_id', 'alert_cycle']);
            $table->index(['user_id', 'created_at']);
            $table->index(['game_id', 'source', 'offer_kind']);
        });

        Schema::create('alert_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_event_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('channel', 20)->default('telegram');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_deliveries');
        Schema::dropIfExists('alert_events');
        Schema::table('favorite_alerts', function (Blueprint $table) {
            $table->dropColumn('cycle');
        });
    }
};
