<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Collecting officer becomes a designation again, as it is in v1.
 *
 * v1's `admin_users.role` is `enum('hrd','field_office')` — there is no
 * collecting-officer role. `collecting-officers.php` lists active field-office
 * and HRD staff as the pool of people who can be *named* on an official
 * receipt. Collecting is something a staff member is designated to do, not a
 * job that replaces the one they already have.
 *
 * v2 modelled it as a role, and because a user has exactly one role — and
 * `field_office_id` is only kept for the field-office role — the combination
 * that actually exists in the office became unrepresentable: a field office's
 * own collecting officer had to give up either their office scoping or their
 * ability to take money.
 *
 * This restores the v1 shape. The role case stays in the enum so existing rows
 * still cast, but nothing is granted by it any more: authorisation moves to
 * this flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_collecting_officer')
                ->default(false)
                ->after('field_office_id');
        });

        /*
         * Anyone who held the role was, by definition, doing the collecting —
         * so they keep the ability. Their role is deliberately left alone: this
         * migration cannot know which field office they belong to, and guessing
         * would either strand them with no scope or hand them the wrong one.
         * Admin\UserController surfaces them for a superadmin to reassign.
         */
        DB::table('users')
            ->where('role', 'collecting-officer')
            ->update(['is_collecting_officer' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_collecting_officer');
        });
    }
};
