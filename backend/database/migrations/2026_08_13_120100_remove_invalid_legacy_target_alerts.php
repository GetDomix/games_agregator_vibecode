<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 2026_07_25_161000 created active target alerts for old plain
        // favorites. Delete only that invalid shape; a null new_low is valid.
        DB::table('favorite_alerts')
            ->where('condition_type', 'target_price')
            ->whereNull('target_value')
            ->delete();

        // A positive legacy projection without a canonical alert is ambiguous:
        // it can be a prior explicit delete. Never resurrect notifications from
        // it; clear the stale projection so no-alert always means plain.
        DB::table('favorites')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('favorite_alerts')
                ->whereColumn('favorite_alerts.favorite_id', 'favorites.id'))
            ->whereNotNull('target_price_rub')
            ->update(['target_price_rub' => null, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Intentionally no-op: deleted rows cannot be distinguished safely from
        // intentionally removed alerts, and recreating them would reintroduce
        // invalid active target alerts.
    }
};
