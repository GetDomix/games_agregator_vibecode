<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('notifications_read_through_id')->default(0);
        });

        Schema::create('site_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('audience_max_user_id')->nullable();
            $table->foreignId('alert_event_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 160);
            $table->text('body');
            $table->json('data')->nullable();
            $table->timestamp('published_at');
            $table->timestamps();

            $table->index(['recipient_user_id', 'id']);
            $table->index(['audience_max_user_id', 'id']);
            $table->index(['published_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_notifications');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notifications_read_through_id');
        });
    }
};
