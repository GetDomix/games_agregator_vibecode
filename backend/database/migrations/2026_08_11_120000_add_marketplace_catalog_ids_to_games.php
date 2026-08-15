<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            // Plati game card id from /games/{slug}/{id_cb}/ (suggest.ashx).
            $table->unsignedInteger('plati_id_cb')->nullable()->after('release_date');
            $table->string('plati_catalog_name', 200)->nullable()->after('plati_id_cb');
            $table->timestamp('plati_catalog_resolved_at')->nullable()->after('plati_catalog_name');

            // GGsel category chip from categories_with_icons.
            $table->string('ggsel_category_slug', 200)->nullable()->after('plati_catalog_resolved_at');
            $table->string('ggsel_category_name', 200)->nullable()->after('ggsel_category_slug');
            $table->timestamp('ggsel_catalog_resolved_at')->nullable()->after('ggsel_category_name');

            $table->index('plati_id_cb');
            $table->index('ggsel_category_slug');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropIndex(['plati_id_cb']);
            $table->dropIndex(['ggsel_category_slug']);
            $table->dropColumn([
                'plati_id_cb',
                'plati_catalog_name',
                'plati_catalog_resolved_at',
                'ggsel_category_slug',
                'ggsel_category_name',
                'ggsel_catalog_resolved_at',
            ]);
        });
    }
};
