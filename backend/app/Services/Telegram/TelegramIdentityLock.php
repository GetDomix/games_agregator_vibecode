<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\DB;

/**
 * Serializes creation or reassignment decisions for one Telegram subject.
 *
 * The advisory transaction lock deliberately precedes every user row lock.
 * All writers then take row locks in the shared order: user, identity.
 */
final class TelegramIdentityLock
{
    public function acquire(string $subject): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [
            'igroscan:telegram:identity:'.$subject,
        ]);
    }
}
