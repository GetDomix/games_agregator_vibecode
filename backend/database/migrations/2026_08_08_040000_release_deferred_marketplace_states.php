<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('game_source_states')
            ->whereIn('source', ['plati', 'ggsel'])
            ->where('status', 'pending')
            ->whereIn('game_id', function ($query): void {
                $query->select('id')
                    ->from('games')
                    ->where(function ($games): void {
                        $games->whereNull('release_status')
                            ->orWhere('release_status', '!=', 'released');
                    });
            })
            ->update([
                'status' => 'stale',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Deferred states must not be restored to an infinite loading state.
    }
};
