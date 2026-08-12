<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v2 stores a full middle name (`mname`), not just an initial. Storing the
     * initial only would lose data on any migration from v2, so the column is
     * widened and renamed; the initial is still what gets rendered in names.
     */
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->renameColumn('middle_initial', 'middle_name');
        });

        Schema::table('profiles', function (Blueprint $table) {
            $table->string('middle_name', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->renameColumn('middle_name', 'middle_initial');
        });

        Schema::table('profiles', function (Blueprint $table) {
            $table->string('middle_initial', 5)->nullable()->change();
        });
    }
};
