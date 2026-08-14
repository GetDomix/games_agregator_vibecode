<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // Authoritative product-list key returned by GET /categories/{slug}
            // and consumed by POST /elastic/goods/categories.
            $table->unsignedBigInteger('ggsel_digi_catalog_id')->nullable()->after('ggsel_category_slug');
            $table->index('ggsel_digi_catalog_id');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropIndex(['ggsel_digi_catalog_id']);
            $table->dropColumn('ggsel_digi_catalog_id');
        });
    }
};
