<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The "hrd" field office row doubles as the catch-all for participants who
     * do not belong to any CSC field office. Its old label, "Human Resource
     * Division", described the internal division rather than the choice a
     * participant is making; v2 called the row "Outside Region VIII / Others".
     * Rename to the participant-facing label.
     */
    public function up(): void
    {
        DB::table('field_offices')
            ->where('code', 'hrd')
            ->update(['name' => 'Outside Region VIII']);
    }

    public function down(): void
    {
        DB::table('field_offices')
            ->where('code', 'hrd')
            ->update(['name' => 'Human Resource Division']);
    }
};
