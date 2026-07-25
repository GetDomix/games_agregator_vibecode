<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30);
            $table->string('provider_subject', 128);
            $table->json('profile')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_subject']);
        });

        Schema::create('oidc_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('state', 128)->unique();
            $table->string('nonce', 128);
            $table->string('code_verifier', 128);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oidc_transactions');
        Schema::dropIfExists('external_identities');
    }
};
