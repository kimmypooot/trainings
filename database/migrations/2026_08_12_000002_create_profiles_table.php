<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Personal information
            $table->string('first_name');
            $table->string('middle_initial', 5)->nullable();
            $table->string('last_name');
            $table->string('suffix')->nullable();
            $table->date('date_of_birth');
            $table->string('sex');
            $table->boolean('is_pwd')->default(false);
            $table->string('civil_status');
            $table->string('mobile_number', 30);

            // Employment details
            $table->string('position_title');
            $table->string('salary_grade');
            $table->string('organization_name');
            $table->string('sector');
            $table->string('csc_field_office');
            $table->string('position_level');
            $table->string('employment_status');
            $table->text('organization_address');
            $table->boolean('has_food_restrictions')->default(false);

            $table->timestamp('consented_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('profile_completed_at')->nullable()->after('google_avatar');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('profile_completed_at');
        });

        Schema::dropIfExists('profiles');
    }
};
