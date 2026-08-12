<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->foreignId('field_office_id')
                ->nullable()
                ->after('csc_field_office')
                ->constrained('field_offices')
                ->nullOnDelete();
        });

        // Backfill from the free-text value captured before the table existed.
        // "Outside Region VIII / Others" was the label for the HRD row.
        foreach (DB::table('field_offices')->get(['id', 'name']) as $office) {
            DB::table('profiles')
                ->where('csc_field_office', $office->name)
                ->update(['field_office_id' => $office->id]);
        }

        $hrd = DB::table('field_offices')->where('code', 'hrd')->value('id');

        if ($hrd) {
            DB::table('profiles')
                ->whereNull('field_office_id')
                ->where('csc_field_office', 'Outside Region VIII / Others')
                ->update(['field_office_id' => $hrd]);
        }

        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('csc_field_office');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->string('csc_field_office')->nullable()->after('city_municipality');
            $table->dropConstrainedForeignId('field_office_id');
        });
    }
};
