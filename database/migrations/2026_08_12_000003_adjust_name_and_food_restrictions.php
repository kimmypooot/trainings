<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The display name is composed from the profile's name parts, so it is
        // not known at registration time any more.
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
        });

        Schema::table('profiles', function (Blueprint $table) {
            $table->text('food_restrictions_details')->nullable()->after('has_food_restrictions');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('food_restrictions_details');
        });
    }
};
