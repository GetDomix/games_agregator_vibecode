<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE favorite_alerts AS alerts
            SET cycle = cycles.max_cycle + 1, triggered_at = NULL, updated_at = NOW()
            FROM (
                SELECT alerts.id, MAX(events.alert_cycle) AS max_cycle
                FROM favorite_alerts AS alerts
                JOIN alert_events AS events ON events.favorite_alert_id = alerts.id
                WHERE alerts.status = 'active'
                  AND EXISTS (
                    SELECT 1 FROM alert_events AS occupied
                    WHERE occupied.favorite_alert_id = alerts.id
                      AND occupied.alert_cycle = alerts.cycle
                  )
                GROUP BY alerts.id
            ) AS cycles
            WHERE alerts.id = cycles.id
        SQL);
    }

    public function down(): void
    {
        // No-op: the prior occupied generation is not safely recoverable.
    }
};
