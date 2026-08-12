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
            // v2 splits the workplace address into administrative units and
            // keeps a separate home address.
            $table->string('agency_unit')->nullable()->after('organization_name');
            $table->string('region')->nullable()->after('sector');
            $table->string('province')->nullable()->after('region');
            $table->string('city_municipality')->nullable()->after('province');
            $table->text('home_address')->nullable()->after('organization_address');
        });

        // v2 stores food restrictions as free text with no yes/no flag: text
        // present means there are restrictions.
        DB::table('profiles')
            ->where('has_food_restrictions', false)
            ->update(['food_restrictions_details' => null]);

        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('has_food_restrictions');
        });

        // Civil status: v2 offers Divorced, not Annulled.
        DB::table('profiles')->where('civil_status', 'Annulled')->update(['civil_status' => 'Divorced']);
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->boolean('has_food_restrictions')->default(false)->after('organization_address');
            $table->dropColumn(['agency_unit', 'region', 'province', 'city_municipality', 'home_address']);
        });
    }
};
