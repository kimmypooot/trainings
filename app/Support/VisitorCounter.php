<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * A single-row counter for the footer's visitor tally.
 *
 * Counted once per session rather than per request, matching how the RO VIII
 * portal behaves, so a refresh does not inflate the number.
 */
class VisitorCounter
{
    private const KEY = 'site';

    private const SESSION_FLAG = 'visitor_counted';

    public static function total(): int
    {
        return (int) DB::table('site_visits')->where('key', self::KEY)->value('total');
    }

    public static function countOnce(): int
    {
        if (session(self::SESSION_FLAG)) {
            return self::total();
        }

        // insertOrIgnore, not upsert: an upsert with no update columns degrades
        // to a plain insert and collides on the primary key after the first row.
        DB::table('site_visits')->insertOrIgnore([
            'key' => self::KEY,
            'total' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('site_visits')->where('key', self::KEY)->increment('total', 1, ['updated_at' => now()]);

        session([self::SESSION_FLAG => true]);

        return self::total();
    }
}
