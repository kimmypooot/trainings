<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The office a staff member belongs to. Only meaningful for the
     * field-office role, which is scoped to the participants of that office.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('field_office_id')
                ->nullable()
                ->after('role')
                ->constrained('field_offices')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('field_office_id');
        });
    }
};
