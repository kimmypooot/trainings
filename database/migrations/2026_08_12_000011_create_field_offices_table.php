<?php

use App\Support\FieldOfficeReference;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_offices', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique()->comment('Short code, e.g. bfo, lfoi');
            $table->string('name');
            $table->string('type')->default('field_office');
            $table->string('province', 64);
            $table->json('jurisdiction')->nullable()->comment('Provinces covered');
            $table->string('address', 500)->nullable();
            $table->string('contact_number', 32)->nullable();
            $table->string('email', 128)->nullable();
            $table->string('head_name', 128)->nullable();
            $table->string('head_position', 128)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index('is_active');
        });

        // Seeded here, not left to a seeder: the next migration links existing
        // profiles to these rows, and would silently match nothing against an
        // empty table.
        DB::table('field_offices')->insert(array_map(fn (array $office) => [
            ...$office,
            'jurisdiction' => json_encode($office['jurisdiction']),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], FieldOfficeReference::all()));
    }

    public function down(): void
    {
        Schema::dropIfExists('field_offices');
    }
};
