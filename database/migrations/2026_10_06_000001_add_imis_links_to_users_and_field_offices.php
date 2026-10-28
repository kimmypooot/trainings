<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * IMIS is where staff are managed. `users.imis_user_id` ties a staff
     * account to its IMIS account (set on the first sign-in from IMIS), and a
     * field office's `imis_code` ties it to the IMIS organizational unit that
     * office corresponds to. Participants are external and never have either.
     */
    private const OFFICES = [
        'bfo' => 'BFO',
        'lfoi' => 'LFO-I',
        'lfoii' => 'LFO-II',
        'slfo' => 'SLFO',
        'wlso' => 'WLSO',
        'sfo' => 'SFO',
        'esfo' => 'ESFO',
        'nsfo' => 'NSFO',
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('imis_user_id')->nullable()->unique()->after('id');
        });

        Schema::table('field_offices', function (Blueprint $table) {
            $table->string('imis_code', 30)->nullable()->unique()->after('code');
        });

        foreach (self::OFFICES as $code => $imisCode) {
            DB::table('field_offices')->where('code', $code)->update(['imis_code' => $imisCode]);
        }
    }

    public function down(): void
    {
        Schema::table('field_offices', function (Blueprint $table) {
            $table->dropUnique(['imis_code']);
            $table->dropColumn('imis_code');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['imis_user_id']);
            $table->dropColumn('imis_user_id');
        });
    }
};
