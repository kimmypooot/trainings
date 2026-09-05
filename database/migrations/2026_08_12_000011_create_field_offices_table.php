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

        $offices = FieldOfficeReference::all();

        /*
         * An empty list is a broken install, not an empty office.
         *
         * The rows come from a data file each deployment supplies, so a missing
         * file or invalid JSON decodes to nothing — and every failure downstream
         * of that is silent. The next migration links profiles to these rows and
         * matches none; field-office scoping then resolves to 0, failing closed,
         * so every field-office account sees an empty system and nothing
         * anywhere says why. Better to stop here, where the message can name the
         * file.
         */
        if ($offices === []) {
            throw new RuntimeException(
                'No field offices to seed. '.FieldOfficeReference::path().' is missing, empty, or not valid JSON. '
                .'It lists the offices this deployment serves and must be in place before migrating — see docs/deployment.md.'
            );
        }

        // Seeded here, not left to a seeder: the next migration links existing
        // profiles to these rows, and would silently match nothing against an
        // empty table.
        DB::table('field_offices')->insert(array_map(fn (array $office) => [
            ...$office,
            'jurisdiction' => json_encode($office['jurisdiction']),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $offices));
    }

    public function down(): void
    {
        Schema::dropIfExists('field_offices');
    }
};
